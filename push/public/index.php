<?php
// ==========================================
// 1. البيانات والحساب الحقيقي
// ==========================================
define('CLIENT_ID', 'd14f70274313451fae3cdf2de370e09a');
define('CLIENT_SECRET', 'UzngAXjlbO5NXEej8RDh28QcYHxbbTJn');
define('MSISDN', '9647887276016');

// مسار التوكن المباشر
define('API_URL', 'https://pg-api.zaincash.iq/oauth2/token');

// استخراج النطاق الرئيسي من مسار التوكن
$baseUrl = parse_url(API_URL, PHP_URL_SCHEME) . "://" . parse_url(API_URL, PHP_URL_HOST);

define('SUCCESS_URL', 'http://krar.top/index.php?status=success');
define('FAILURE_URL', 'http://krar.top/index.php?status=failure');

function generate_uuidv4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ==========================================
// 2. معالجة طلب إنشاء عملية الدفع
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay') {
    header('Content-Type: application/json');

    $amountVal     = (string)intval($_POST['amount'] ?? 1000);
    $serviceType   = $_POST['serviceType'] ?? 'Delivery';
    $customerPhone = $_POST['phone'] ?? MSISDN;

    // الخطوة الأولى: جلب Access Token باستخدام API_URL المباشر
    $tokenData = http_build_query([
        "grant_type"    => "client_credentials",
        "client_id"     => CLIENT_ID,
        "client_secret" => CLIENT_SECRET,
        "scope"         => "payment:read payment:write reverse:write reverse:read disbursement:read disbursement:write"
    ]);

    $chToken = curl_init(API_URL);
    curl_setopt_array($chToken, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $tokenData,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/x-www-form-urlencoded"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $tokenResponse = curl_exec($chToken);
    $httpCodeToken = curl_getinfo($chToken, CURLINFO_HTTP_CODE);
    curl_close($chToken);

    if ($httpCodeToken !== 200) {
        echo json_encode(['success' => false, 'message' => "خطأ جلب Token ($httpCodeToken): " . $tokenResponse]);
        exit;
    }

    $tokenJson   = json_decode($tokenResponse, true);
    $accessToken = $tokenJson['access_token'] ?? null;

    if (!$accessToken) {
        echo json_encode(['success' => false, 'message' => 'لم يتم استلام access_token من النظام.']);
        exit;
    }

    // الخطوة الثانية: إرسال الـ Payload للبدء بالمعاملة
    $initUrl = $baseUrl . "/api/v2/payment-gateway/transaction/init";

    $payload = [
        "language"            => "ar",
        "externalReferenceId" => generate_uuidv4(),
        "orderId"             => "ORD-" . time(),
        "amount"              => [
            "value"    => $amountVal,
            "currency" => "IQD"
        ],
        "customer"            => [
            "phone" => $customerPhone
        ],
        "serviceType"         => $serviceType,
        "redirectUrls"        => [
            "successUrl" => SUCCESS_URL,
            "failureUrl" => FAILURE_URL
        ]
    ];

    $chInit = curl_init($initUrl);
    curl_setopt_array($chInit, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $accessToken
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $initResponse = curl_exec($chInit);
    $httpCodeInit = curl_getinfo($chInit, CURLINFO_HTTP_CODE);
    curl_close($chInit);

    $initJson = json_decode($initResponse, true);

    if ($httpCodeInit === 200 || $httpCodeInit === 201) {
        $payUrl = $initJson['redirectUrl'] ?? $initJson['paymentUrl'] ?? null;
        if (!$payUrl && isset($initJson['id'])) {
            $payUrl = $baseUrl . "/transaction/pay?id=" . $initJson['id'];
        }

        if ($payUrl) {
            echo json_encode(['success' => true, 'payUrl' => $payUrl]);
        } else {
            echo json_encode(['success' => false, 'message' => 'لم يرجع رابط التحويل: ' . $initResponse]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "فشل إنشاء المعاملة ($httpCodeInit): " . $initResponse]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZainCash Live V2 | krar.top</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background-color: #f8f9fa; color: #333; }
        .header-banner { background: linear-gradient(135deg, #ff007a, #7a00ff); color: white; padding: 40px 0; border-radius: 0 0 24px 24px; margin-bottom: 40px; }
        .payment-card { background: #ffffff; border-radius: 16px; box-shadow: 0 8px 25px rgba(0,0,0,0.06); padding: 32px; border: 1px solid #eee; }
        .btn-zain { background-color: #ff007a; color: white; font-weight: 700; padding: 12px 24px; border-radius: 8px; border: none; }
        .btn-zain:hover { background-color: #d60066; color: white; }
    </style>
</head>
<body>

    <div class="header-banner text-center">
        <div class="container">
            <h1 class="fw-bold">بوابة دفع</h1>
        </div>
    </div>

    <div class="container mb-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="payment-card">
                    
                    <?php if (isset($_GET['status'])): ?>
                        <?php if ($_GET['status'] === 'success'): ?>
                            <div class="alert alert-success text-center">
                                <h4 class="fw-bold">✓ تم إكمال العملية بنجاح!</h4>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger text-center">
                                <h4 class="fw-bold">✕ فشلت عملية الدفع</h4>
                            </div>
                        <?php endif; ?>
                        <a href="index.php" class="btn btn-secondary w-100 mt-3">إجراء عملية جديدة</a>

                    <?php else: ?>
                        <h4 class="fw-bold mb-4 text-center">إنشاء معاملة دفع حقيقية</h4>
                        <form id="paymentForm">
                            <div class="mb-3">
                                <label class="form-label font-weight-bold">نوع الخدمة (serviceType)</label>
                                <input type="text" id="serviceType" class="form-control" value="Delivery" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label font-weight-bold">رقم الزبون (Phone)</label>
                                <input type="text" id="phone" class="form-control" value="9647887276016" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label font-weight-bold">المبلغ بالدينار (IQD)</label>
                                <input type="number" id="amount" class="form-control" value="1000" min="250" step="250" required>
                            </div>

                            <button type="submit" id="submitBtn" class="btn btn-zain w-100">
                                تنفيذ الدفع المباشر
                            </button>
                        </form>

                        <div id="statusMessage" class="mt-4" style="display: none;"></div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('paymentForm');
        if (form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = document.getElementById('submitBtn');
                const statusDiv = document.getElementById('statusMessage');

                btn.disabled = true;
                btn.innerHTML = 'جاري المعالجة...';
                statusDiv.style.display = 'block';
                statusDiv.className = 'mt-4 alert alert-info';
                statusDiv.innerHTML = 'جاري الاتصال بـ ZainCash...';

                const formData = new FormData();
                formData.append('action', 'pay');
                formData.append('amount', document.getElementById('amount').value);
                formData.append('serviceType', document.getElementById('serviceType').value);
                formData.append('phone', document.getElementById('phone').value);

                try {
                    const res = await fetch('index.php', { method: 'POST', body: formData });
                    const data = await res.json();

                    if (data.success && data.payUrl) {
                        statusDiv.className = 'mt-4 alert alert-success';
                        statusDiv.innerHTML = 'تم النجاح! جاري تحويلك إلى بوابة الدفع...';
                        setTimeout(() => { window.location.href = data.payUrl; }, 800);
                    } else {
                        statusDiv.className = 'mt-4 alert alert-danger';
                        statusDiv.innerHTML = data.message;
                        btn.disabled = false;
                        btn.innerHTML = 'تنفيذ الدفع المباشر V2';
                    }
                } catch (err) {
                    statusDiv.className = 'mt-4 alert alert-danger';
                    statusDiv.innerHTML = 'حدث خطأ غير متوقع: ' + err.message;
                    btn.disabled = false;
                    btn.innerHTML = 'تنفيذ الدفع المباشر V2';
                }
            });
        }
    </script>
</body>
</html>
