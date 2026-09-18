<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }
/**
 * config/paymongo.php
<<<<<<< HEAD
 * TEST MODE ONLY.
 *
 * PayMongo's test keys work immediately after signup — no business
 * permit, DTI/SEC registration, or verification required. They only
 * ask for that once you try to switch a project to Live mode.
 *
 * To enable:
 *   1. Sign up at https://dashboard.paymongo.com
 *   2. Make sure the dashboard's mode switch (top left) is on "Test mode".
 *   3. Developers -> API Keys -> copy the Secret key (sk_test_...) and
 *      Public key (pk_test_...) below.
 *   4. Pay with PayMongo's published test numbers/accounts (see
 *      https://developers.paymongo.com/docs/testing) — no real money
 *      moves in test mode, regardless of which "channel" you pick.
 *
 * Never paste live keys (sk_live_/pk_live_) in here — this file and
 * the flow it drives (tenant/pay_paymongo.php, tenant/paymongo_return.php)
 * assume test mode only.
 */

define('PAYMONGO_SECRET_KEY', 'sk_test_REPLACE_ME');
define('PAYMONGO_PUBLIC_KEY', 'pk_test_REPLACE_ME');

/** True once real test keys have been filled in above. */
function paymongo_configured(): bool
{
    return str_starts_with(PAYMONGO_SECRET_KEY, 'sk_test_') && PAYMONGO_SECRET_KEY !== 'sk_test_REPLACE_ME';
}

/**
 * Low-level call to the PayMongo REST API. Throws RuntimeException
 * with a message safe to show a tenant on any failure.
 */
function paymongo_request(string $method, string $path, ?array $body = null): array
{
    if (!paymongo_configured()) {
        throw new RuntimeException('Online payments aren\'t set up yet. Please use "Submit Payment" instead, or contact the office.');
    }

    $ch = curl_init('https://api.paymongo.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Could not reach PayMongo: ' . $curlError);
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $message = $data['errors'][0]['detail'] ?? 'PayMongo request failed (HTTP ' . $httpCode . ').';
        throw new RuntimeException($message);
    }

    return $data;
}

/**
 * Creates a hosted Checkout Session covering one payment. Returns the
 * decoded 'data' object (id, attributes.checkout_url, ...).
 */
function paymongo_create_checkout_session(
    float $amountPesos,
    string $description,
    string $successUrl,
    string $cancelUrl,
    string $tenantName,
    string $tenantEmail
): array {
    $result = paymongo_request('POST', '/checkout_sessions', [
        'data' => [
            'attributes' => [
                'billing' => ['name' => $tenantName, 'email' => $tenantEmail],
                'send_email_receipt' => false,
                'show_description' => true,
                'show_line_items' => true,
                'description' => $description,
                'line_items' => [[
                    'amount'   => (int) round($amountPesos * 100), // centavos
                    'currency' => 'PHP',
                    'name'     => $description,
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['gcash', 'card', 'paymaya'],
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
            ],
        ],
    ]);

    return $result['data'];
}

/** Fetches a Checkout Session's current state. Returns the decoded 'data' object. */
function paymongo_get_checkout_session(string $checkoutSessionId): array
{
    $result = paymongo_request('GET', '/checkout_sessions/' . urlencode($checkoutSessionId));
    return $result['data'];
}
=======
 * Credentials for the PayMongo GCash integration.
 *
 * Where to get these: PayMongo Dashboard -> Developers -> API Keys.
 *
 *  - TEST keys (sk_test_..., pk_test_...) work as soon as you finish
 *    basic sign-up + KYC (email + government ID + liveness check).
 *    You do NOT need a TIN or full business verification (KYB) to
 *    build and test this whole flow — test mode never moves real
 *    money, so build against sk_test_/pk_test_ today.
 *
 *  - LIVE keys (sk_live_..., pk_live_...) only work once your account
 *    is "Activated" for real payments, which does require KYB
 *    (business info + TIN) for most business types. Swap the values
 *    below to your live keys + live webhook secret when that's done —
 *    nothing else in the code needs to change.
 *
 * Never commit real keys to a public repo. If this project is on
 * GitHub, add config/paymongo.php to .gitignore and commit
 * config/paymongo.example.php instead.
 */

>>>>>>> origin/james
