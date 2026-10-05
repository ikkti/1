<?php
require_once __DIR__ . '/app_core.php';
app_bootstrap();
header('X-Content-Type-Options: nosniff');

const VERIFICATION_PRICE_IQD = 2000;

// Keep compatibility with the credentials already shipped in the project's ZainCash files.
$clientId = getenv('ZAINCASH_CLIENT_ID') ?: 'd14f70274313451fae3cdf2de370e09a';
$clientSecret = getenv('ZAINCASH_CLIENT_SECRET') ?: 'UzngAXjlbO5NXEej8RDh28QcYHxbbTJn';
$msisdn = getenv('ZAINCASH_MSISDN') ?: '9647887276016';
$apiUrl = rtrim(getenv('ZAINCASH_API_URL') ?: 'https://pg-api.zaincash.iq', '/');

function zc_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function zc_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function zc_b64url_decode(string $value): string {
    $value = strtr($value, '-_', '+/');
    return base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
}

function zc_verify_callback_token(string $jwt, string $secret): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h, $p, $sig] = $parts;
    $header = json_decode(zc_b64url_decode($h), true);
    $payload = json_decode(zc_b64url_decode($p), true);
    if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'HS256') return null;
    $expected = hash_hmac('sha256', $h . '.' . $p, $secret, true);
    if (!hash_equals($expected, zc_b64url_decode($sig))) return null;
    if (!empty($payload['exp']) && (int)$payload['exp'] < time()) return null;
    return $payload;
}

function zc_find_request_by_order_id(string $orderId): ?array {
    foreach (app_get_verification_requests()['requests'] ?? [] as $request) {
        if ((string)($request['order_id'] ?? '') === $orderId) return $request;
    }
    return null;
}

function zc_get_access_token(string $apiUrl, string $clientId, string $clientSecret): array {
    $tokenData = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'payment:read payment:write reverse:write reverse:read disbursement:read disbursement:write',
    ]);
    $ch = curl_init($apiUrl . '/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $tokenData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) return ['success' => false, 'error' => 'تعذر الحصول على رمز الوصول من ZainCash: ' . ($error ?: $body)];
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['access_token'])) return ['success' => false, 'error' => 'استجابة ZainCash غير صالحة'];
    return ['success' => true, 'token' => $json['access_token']];
}

if (($_GET['action'] ?? '') === 'callback') {
    $token = trim((string)($_GET['token'] ?? ''));
    $payload = $token !== '' ? zc_verify_callback_token($token, $clientSecret) : null;
    $status = is_array($payload) ? strtoupper((string)($payload['status'] ?? '')) : '';
    $orderId = is_array($payload) ? (string)($payload['orderId'] ?? '') : '';
    $request = $orderId !== '' ? zc_find_request_by_order_id($orderId) : null;

    if ($payload && $request && $status === 'SUCCESS') {
        app_complete_paid_verification((string)$request['request_id'], [
            'provider' => 'ZainCash',
            'status' => $status,
            'transaction_id' => $payload['id'] ?? ($payload['transactionId'] ?? ''),
            'order_id' => $orderId,
        ]);
        header('Location: index.php?payment=success');
        exit;
    }

    header('Location: index.php?payment=failure');
    exit;
}

$user = !empty($_SESSION['logged_in']) ? app_find_user_by_id((int)$_SESSION['user_id']) : null;
if (!$user) {
    header('Location: index.php');
    exit;
}

$requestId = trim((string)($_GET['request_id'] ?? ''));
$request = $requestId !== '' ? app_find_verification_request_by_id($requestId) : null;
if (!$request || (int)($request['user_id'] ?? 0) !== (int)$user['id'] || ($request['status'] ?? '') !== 'payment_pending') {
    http_response_code(400);
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="background:#080808;color:#fff;font-family:Arial;text-align:center;padding:60px"><h2>طلب الدفع غير صالح أو تمت معالجته.</h2><a href="index.php" style="color:#ff3344">العودة للموقع</a></body></html>';
    exit;
}

if (($_GET['action'] ?? '') === 'pay') {
    $token = zc_get_access_token($apiUrl, $clientId, $clientSecret);
    if (!$token['success']) {
        http_response_code(502);
        echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="background:#080808;color:#fff;font-family:Arial;text-align:center;padding:40px"><h2>تعذر الاتصال ببوابة ZainCash</h2><p style="color:#ff7780">' . htmlspecialchars($token['error']) . '</p><a href="index.php" style="color:#ff3344">العودة للموقع</a></body></html>';
        exit;
    }

    $base = rtrim(app_get_base_url(), '/');
    $payload = [
        'language' => 'ar',
        'externalReferenceId' => zc_uuid(),
        'orderId' => (string)$request['order_id'],
        'amount' => ['value' => VERIFICATION_PRICE_IQD, 'currency' => 'IQD'],
        'customer' => ['phone' => $msisdn],
        'serviceType' => 'Monthly Verification',
        'redirectUrls' => [
            'successUrl' => $base . '/zaincash.php?action=callback',
            'failureUrl' => $base . '/zaincash.php?action=callback',
        ],
    ];

    $ch = curl_init($apiUrl . '/api/v2/payment-gateway/transaction/init');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token['token']],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    $payUrl = is_array($json) ? ($json['redirectUrl'] ?? $json['paymentUrl'] ?? null) : null;
    if (!$payUrl && is_array($json) && isset($json['id'])) $payUrl = $apiUrl . '/transaction/pay?id=' . rawurlencode((string)$json['id']);
    if ($httpCode >= 200 && $httpCode < 300 && $payUrl) {
        header('Location: ' . $payUrl);
        exit;
    }

    http_response_code(502);
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="background:#080808;color:#fff;font-family:Arial;text-align:center;padding:40px"><h2>فشل إنشاء عملية الدفع</h2><p style="color:#ff7780">' . htmlspecialchars($curlError ?: (string)$body) . '</p><a href="index.php" style="color:#ff3344">العودة للموقع</a></body></html>';
    exit;
}

header('Location: index.php');
exit;
