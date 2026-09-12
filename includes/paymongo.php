<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * includes/paymongo.php
 * Thin wrapper around the PayMongo v2 Checkout Sessions API, scoped
 * to exactly what this app needs: create a GCash-only checkout and
 * verify the webhook that confirms payment. Not a general-purpose SDK.
 *
 * Docs: https://developers.paymongo.com/docs/checkout-api
 */

/**
 * Low-level authenticated request to the PayMongo API.
 * Throws RuntimeException with a user-facing message on failure.
 */
function paymongo_request(string $method, string $path, ?array $body = null): array
{
    // A full URL is passed through untouched — PayMongo doesn't expose
    // every route on the same API version (see
    // paymongo_get_checkout_session). A leading "/..." is still treated
    // as relative to PAYMONGO_API_BASE, so existing callers are
    // unaffected.
    $url = str_starts_with($path, 'http') ? $path : PAYMONGO_API_BASE . $path;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        // PayMongo auth: secret key as HTTP Basic username, empty password.
        CURLOPT_USERPWD        => PAYMONGO_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Could not reach PayMongo: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true) ?? [];

    if ($status >= 400) {
        $message = $decoded['errors'][0]['detail'] ?? 'PayMongo request failed (HTTP ' . $status . ').';
        throw new RuntimeException($message);
    }

    return $decoded;
}

/**
 * Create a GCash-only Checkout Session for one payment.
 *
 * $amount is in pesos (e.g. 5500.00) — this converts to centavos,
 * which is what PayMongo's API expects.
 * $metadata should include something that lets the webhook find the
 * right row again, e.g. ['payment_id' => 42].
 *
 * Returns ['id' => 'cs_xxx', 'checkout_url' => 'https://...'].
 */
function paymongo_create_gcash_checkout(
    float $amount,
    string $description,
    string $referenceNumber,
    array $metadata,
    string $successUrl,
    string $cancelUrl
): array {
    $centavos = (int) round($amount * 100);

    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name'     => $description,
                    'amount'   => $centavos,
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['gcash'],
                'reference_number'     => $referenceNumber,
                'send_email_receipt'   => false,
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
                'metadata'             => $metadata,
            ],
        ],
    ];

    $response = paymongo_request('POST', '/checkout_sessions', $payload);

    return [
        'id'           => $response['data']['id'] ?? null,
        'checkout_url' => $response['data']['attributes']['checkout_url'] ?? null,
    ];
}

/**
 * Verify the `Paymongo-Signature` header on an incoming webhook
 * request. Must be run against the RAW request body — never the
 * json_decode()'d version — or every signature will fail to match.
 *
 * Header format: t=<timestamp>,te=<test-mode-sig>,li=<live-mode-sig>
 * We accept a match against either te or li, since this app runs the
 * same webhook URL for both test and live mode.
 *
 * https://developers.paymongo.com/docs/securing-webhook
 */
function paymongo_verify_webhook_signature(string $rawPayload, string $signatureHeader, string $secret): bool
{
    $parts = [];
    foreach (explode(',', $signatureHeader) as $chunk) {
        [$key, $value] = array_pad(explode('=', trim($chunk), 2), 2, null);
        if ($key !== null && $value !== null) {
            $parts[$key] = $value;
        }
    }

    if (empty($parts['t']) || (empty($parts['te']) && empty($parts['li']))) {
        return false;
    }

    $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawPayload, $secret);

    foreach (['te', 'li'] as $key) {
        if (!empty($parts[$key]) && hash_equals($expected, $parts[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * Look one Checkout Session up again by id.
 *
 * The webhook is still the source of truth for confirming payments,
 * but it can only fire at a publicly reachable URL — on a plain XAMPP
 * localhost, PayMongo simply can't call back. Re-reading the session
 * lets the tenant's own page confirm the payment itself, so the portal
 * flips to "Paid" on its own either way.
 */
function paymongo_get_checkout_session(string $checkoutId): array
{
    // Checkout Sessions are CREATED on v2 but READ BACK on v1 — v2 has
    // no GET route for them and answers "The requested route does not
    // exist". The version segment is swapped rather than hardcoding a
    // second base URL, so PAYMONGO_API_BASE stays the single place a
    // host change has to be made.
    $readBase = preg_replace('#/v\d+$#', '/v1', PAYMONGO_API_BASE);

    return paymongo_request('GET', $readBase . '/checkout_sessions/' . rawurlencode($checkoutId));
}

/**
 * Boil a Checkout Session response down to what this app cares about.
 *
 * Returns ['status' => 'paid'|'failed'|'unpaid', 'payment_id' => ?string].
 * Anything still in flight (or that PayMongo describes in a way we
 * don't recognise) comes back as 'unpaid' — the safe answer, since it
 * just leaves the payment Pending for the webhook to settle later.
 */
function paymongo_checkout_payment_status(array $session): array
{
    $attributes = $session['data']['attributes'] ?? [];

    // A settled session lists the payment in two places. Check both —
    // they agree in practice, but relying on only one would make this
    // brittle if PayMongo trims either from the payload.
    $payments = array_merge(
        $attributes['payment_intent']['attributes']['payments'] ?? [],
        $attributes['payments'] ?? []
    );

    foreach ($payments as $payment) {
        $status = $payment['attributes']['status'] ?? null;
        if ($status === 'paid') {
            return ['status' => 'paid', 'payment_id' => $payment['id'] ?? null];
        }
    }

    // No paid payment on the session. `payment_intent.status` tells us
    // whether it's still waiting on the customer or genuinely dead.
    $intentStatus = $attributes['payment_intent']['attributes']['status'] ?? null;
    if (in_array($intentStatus, ['succeeded'], true)) {
        return ['status' => 'paid', 'payment_id' => $attributes['payment_intent']['id'] ?? null];
    }
    if (in_array($intentStatus, ['cancelled', 'canceled'], true)) {
        return ['status' => 'failed', 'payment_id' => null];
    }

    return ['status' => 'unpaid', 'payment_id' => null];
}
