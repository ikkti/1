<?php
session_start();
require_once __DIR__ . '/app_core.php';
app_bootstrap();

header('X-Content-Type-Options: nosniff');

// ==========================================
// وظيفة الاتصال المباشر بـ SMTP وإرسال البريد
// ==========================================
function send_smtp_email($to, $subject, $htmlMessage) {
    $smtp_server = 'smtp.foundermail.mx';
    $port = 587;
    $username = 'otp@kruri-valverde.com';
    $password = '1cb17d988c6547eb88a3f5dfc0ee1d4a';
    $from_name = 'كروري فالفيردي';

    $socket = @fsockopen($smtp_server, $port, $errno, $errstr, 15);
    if (!$socket) {
        return ['success' => false, 'error' => "فشل الاتصال بالخادم: $errstr ($errno)"];
    }

    $read = function() use ($socket) {
        $data = '';
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $data;
    };

    $send = function($cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
    };

    $read();
    $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $read();

    // بدء تشفير STARTTLS
    $send("STARTTLS");
    $res = $read();
    if (strpos($res, '220') === false) {
        fclose($socket);
        return ['success' => false, 'error' => 'STARTTLS غير مدعوم على السيرفر'];
    }

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        return ['success' => false, 'error' => 'فشل تفعيل تشفير TLS'];
    }

    $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $read();

    // تسجيل الدخول بالبيانات
    $send("AUTH LOGIN");
    $read();
    $send(base64_encode($username));
    $read();
    $send(base64_encode($password));
    $auth_res = $read();
    if (strpos($auth_res, '235') === false) {
        fclose($socket);
        return ['success' => false, 'error' => 'فشل التحقق من كلمة السر أو اسم المستخدم'];
    }

    // تجهيز مسار الرسالة
    $send("MAIL FROM:<$username>");
    $read();
    $send("RCPT TO:<$to>");
    $read();
    $send("DATA");
    $read();

    // ترويسات ومحتوى البريد
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encoded_name = '=?UTF-8?B?' . base64_encode($from_name) . '?=';

    $headers  = "From: $encoded_name <$username>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: $encoded_subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";

    $body = chunk_split(base64_encode($htmlMessage));

    $send($headers . $body . "\r\n.");
    $final_res = $read();

    $send("QUIT");
    fclose($socket);

    if (strpos($final_res, '250') !== false) {
        return ['success' => true, 'message' => 'تم إرسال رمز التحقق بنجاح'];
    }

    return ['success' => false, 'error' => 'تعذر إرسال البريد عبر الخادم'];
}

// ==========================================
// معالجة طلبات الـ POST والتحقق
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = (string)($input['action'] ?? '');
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $otp = trim((string)($input['otp'] ?? ''));
    $purpose = (string)($input['purpose'] ?? 'password_reset');

    if ($action === 'check') {
        echo json_encode(['success' => true, 'message' => 'verification ready'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'send') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'البريد الإلكتروني غير صالح'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!in_array($purpose, ['register', 'password_reset'], true)) {
            $purpose = 'password_reset';
        }
        $result = app_send_otp_email($email, $purpose);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'verify') {
        if (empty($email) || empty($otp)) {
            echo json_encode(['success' => false, 'error' => 'يرجى إدخال البريد ورمز التحقق'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!in_array($purpose, ['register', 'password_reset'], true)) $purpose = 'password_reset';
        $result = app_verify_otp($email, $purpose, $otp);
        if ($result['success']) {
            $_SESSION['otp_verified_email'] = $email;
            $_SESSION['otp_verified_purpose'] = $purpose;
            $_SESSION['otp_verified_at'] = time();
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'إجراء غير معروف'], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحقق من البريد الإلكتروني</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Cairo',sans-serif;background:linear-gradient(180deg,#f8fbff,#eef4ff);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#0f172a}
        .card{width:100%;max-width:520px;background:#ffffff;border:1px solid rgba(148,163,184,.24);border-radius:28px;padding:30px;box-shadow:0 24px 60px rgba(15,23,42,.10)}
        h1{font-size:28px;margin-bottom:10px}
        p{color:#5b6b84;line-height:1.8;margin-bottom:18px}
        .field{margin-bottom:14px}
        input,select,button{width:100%;border:none;border-radius:16px;font-family:inherit}
        input,select{background:#fff;border:1px solid #cbd5e1;color:#0f172a;padding:14px 16px;font-size:15px}
        button{padding:14px 16px;font-size:15px;font-weight:800;cursor:pointer;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff}
        .status{margin-top:14px;border-radius:14px;padding:12px 14px;display:none}
        .status.success{display:block;background:rgba(16,185,129,.14);border:1px solid rgba(16,185,129,.34);color:#059669}
        .status.error{display:block;background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.34);color:#dc2626}
        .status.info{display:block;background:rgba(59,130,246,.14);border:1px solid rgba(59,130,246,.34);color:#2563eb}
    .card{background:#111;border-color:#5d1018;box-shadow:0 24px 70px rgba(0,0,0,.5)}body{background:radial-gradient(circle at top,#320507,#080808 70%);color:#f5f5f5}h1{color:#fff}p{color:#aaa}input,select{background:#090909;border-color:#4b1118;color:#fff}button{background:linear-gradient(135deg,#5d000b,#d1111c)}.status.info{background:rgba(120,0,12,.16);border-color:#5d1018;color:#ff7b84}</style>
</head>
<body>
    <div class="card">
        <h1>التحقق من البريد</h1>
        <p>أدخل بريدك الإلكتروني لاستلام رمز التأكيد وتفعيل حسابك أو استعادة كلمة المرور.</p>
        <div class="field"><input id="email" type="email" placeholder="البريد الإلكتروني"></div>
        <div class="field">
            <select id="purpose">
                <option value="password_reset">استعادة كلمة المرور</option>
                <option value="register">تأكيد البريد عند التسجيل</option>
            </select>
        </div>
        <button onclick="sendCode()">إرسال الرمز</button>
        <div class="field" style="margin-top:14px"><input id="otp" type="text" maxlength="4" placeholder="رمز التحقق (4 أرقام)"></div>
        <button onclick="verifyCode()">تحقق من الرمز</button>
        <div id="status" class="status"></div>
    </div>
<script>
function setStatus(message, type='info'){
    const box = document.getElementById('status');
    box.className = 'status ' + type;
    box.textContent = message;
}
async function sendCode(){
    const email = document.getElementById('email').value.trim();
    const purpose = document.getElementById('purpose').value;
    if(!email){ setStatus('يرجى كتابة البريد أولاً', 'error'); return; }
    setStatus('جاري إرسال الرمز عبر الخادم...', 'info');
    try {
        const res = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'send', email, purpose})
        });
        const data = await res.json();
        setStatus(data.message || data.error || 'تم', data.success ? 'success' : 'error');
    } catch(e) {
        setStatus('حدث خطأ في الاتصال بالخادم', 'error');
    }
}
async function verifyCode(){
    const email = document.getElementById('email').value.trim();
    const purpose = document.getElementById('purpose').value;
    const otp = document.getElementById('otp').value.trim();
    if(!otp){ setStatus('يرجى إدخال رمز التحقق', 'error'); return; }
    setStatus('جاري التحقق من الرمز...', 'info');
    try {
        const res = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'verify', email, otp, purpose})
        });
        const data = await res.json();
        setStatus(data.message || data.error || 'تم', data.success ? 'success' : 'error');
    } catch(e) {
        setStatus('حدث خطأ أثناء فحص الرمز', 'error');
    }
}
</script>
</body>
</html>
