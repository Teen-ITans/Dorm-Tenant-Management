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
 * $billing is the person actually paying (['name', 'email', 'phone']).
 * Send it: it pre-fills the checkout page and is what identifies the
 * payer in the PayMongo dashboard. Leave it out and the field starts
 * blank, so whoever's browser is being used autofills someone else's
 * details and every tenant's payment looks like it came from them.
 *
 * Returns ['id' => 'cs_xxx', 'checkout_url' => 'https://...'].
 */
function paymongo_create_gcash_checkout(
    float $amount,
    string $description,
    string $referenceNumber,
    array $metadata,
    string $successUrl,
    string $cancelUrl,
    array $billing = []
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

    // Blanks are dropped rather than sent as null — PayMongo rejects an
    // empty string where it expects a phone number or address.
    $billing = array_filter($billing, static fn ($value) => $value !== null && $value !== '');
    if ($billing) {
        $payload['data']['attributes']['billing'] = $billing;
    }

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
 * Build a v1 URL for a Checkout Session route.
 *
 * Sessions are CREATED on v2 but read back and expired on v1 — v2 has
 * no route for either and answers "The requested route does not exist".
 * The version segment is swapped rather than hardcoding a second base
 * URL, so PAYMONGO_API_BASE stays the single place a host change has to
 * be made.
 */
function paymongo_v1_url(string $path): string
{
    return preg_replace('#/v\d+$#', '/v1', PAYMONGO_API_BASE) . $path;
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
    return paymongo_request('GET', paymongo_v1_url('/checkout_sessions/' . rawurlencode($checkoutId)));
}

/**
 * Expire a Checkout Session so nobody can pay it any more.
 *
 * Run this before discarding an abandoned payment row: a checkout link
 * the tenant left open in another tab could otherwise still be paid
 * after the portal had stopped tracking it, and that money would never
 * show up against their rent. Returns false if PayMongo refused (an
 * already-paid session can't be expired) — the caller must then keep
 * the row rather than risk losing a real payment.
 */
function paymongo_expire_checkout_session(string $checkoutId): bool
{
    try {
        paymongo_request('POST', paymongo_v1_url('/checkout_sessions/' . rawurlencode($checkoutId) . '/expire'));
        return true;
    } catch (Throwable $e) {
        // A session that's already expired refuses a second expiry —
        // but that's the state we were asking for, so check before
        // reporting failure and stranding the row.
        try {
            $session = paymongo_get_checkout_session($checkoutId);
            if (($session['data']['attributes']['status'] ?? null) === 'expired') {
                return true;
            }
        } catch (Throwable $ignored) {
        }

        error_log('GCash: could not expire checkout ' . $checkoutId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Boil a Checkout Session response down to what this app cares about.
 *
 * Returns ['status' => ..., 'payment_id' => ?string], where status is:
 *   paid      — money arrived, settle the row
 *   failed    — a real attempt was made and declined
 *   abandoned — the tenant never even picked a payment method, so
 *               nothing happened and nothing is outstanding; the row
 *               can be thrown away rather than shown as a debt
 *   unpaid    — an attempt is in flight, or PayMongo described this in
 *               a way we don't recognise. The safe answer: leave the
 *               payment Pending and look again later.
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

    $declined = false;
    $inFlight = false;

    foreach ($payments as $payment) {
        $status = $payment['attributes']['status'] ?? null;
        if ($status === 'paid') {
            return ['status' => 'paid', 'payment_id' => $payment['id'] ?? null];
        }
        if ($status === 'failed') {
            $declined = true;
        } else {
            $inFlight = true;
        }
    }

    // No paid payment listed. The session's own status and its
    // payment_intent together say whether anything is still outstanding.
    $intentStatus  = $attributes['payment_intent']['attributes']['status'] ?? null;
    $sessionStatus = $attributes['status'] ?? null;

    if ($intentStatus === 'succeeded' || $sessionStatus === 'paid') {
        return ['status' => 'paid', 'payment_id' => $attributes['payment_intent']['id'] ?? null];
    }
    if ($inFlight) {
        return ['status' => 'unpaid', 'payment_id' => null];
    }
    if ($declined || in_array($intentStatus, ['cancelled', 'canceled'], true)) {
        return ['status' => 'failed', 'payment_id' => null];
    }

    // Nothing was ever attempted here. PayMongo only attaches a
    // payment_intent once the customer picks a method, so a null one on
    // a live session means they opened the link and walked away; an
    // expired session can't be paid any more either way. A declined
    // attempt also leaves the intent at awaiting_payment_method, which
    // is why the $declined check above has to run first.
    if ($sessionStatus === 'expired'
        || $intentStatus === 'awaiting_payment_method'
        || ($sessionStatus === 'active' && ($attributes['payment_intent'] ?? null) === null)) {
        return ['status' => 'abandoned', 'payment_id' => null];
    }

    return ['status' => 'unpaid', 'payment_id' => null];
}
