<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Baghdad');

const APP_OTP_VALIDITY_MINUTES = 10;
const APP_MAX_OTP_ATTEMPTS = 5;
const APP_RESEND_COOLDOWN_SECONDS = 20;

// Legacy SMTP credentials retained from the original project; environment variables take precedence.
const LEGACY_ZOHO_SMTP_HOST = 'smtppro.zoho.com';
const LEGACY_ZOHO_SMTP_USERNAME = 'support@kruri-valverde.com';
const LEGACY_ZOHO_SMTP_PASSWORD = '9iGXtkwY2MJu';

function app_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function app_root_path(string $file = ''): string
{
    return __DIR__ . ($file !== '' ? '/' . ltrim($file, '/') : '');
}

function app_storage_path(string $file = ''): string
{
    $dir = app_root_path('storage');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . ($file !== '' ? '/' . ltrim($file, '/') : '');
}

function app_ensure_file(string $file, array $default): void
{
    if (!file_exists($file)) {
        file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

function app_read_json(string $file, array $default = []): array
{
    if (!file_exists($file)) {
        return $default;
    }

    $content = file_get_contents($file);
    if ($content === false || trim($content) === '') {
        return $default;
    }

    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}

function app_write_json(string $file, array $data): bool
{
    return (bool) file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function app_users_file(): string
{
    return app_root_path('users.json');
}

function app_posts_file(): string
{
    return app_root_path('posts.json');
}

function app_otps_file(): string
{
    return app_storage_path('otp_codes.json');
}

function app_settings_file(): string
{
    return app_storage_path('settings.json');
}

function app_verification_requests_file(): string
{
    return app_storage_path('verification_requests.json');
}

function app_uploads_dir(): string
{
    $dir = app_root_path('uploads');
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function app_default_email_template(): string
{
    return <<<HTML
<div style="background:#090909;padding:32px 16px;direction:rtl;text-align:right;font-family:Tahoma,Arial,sans-serif;color:#f5f5f5;">
  <div style="max-width:620px;margin:0 auto;background:#111111;border:1px solid #3a0808;border-radius:22px;overflow:hidden;box-shadow:0 20px 55px rgba(0,0,0,.45);">
    <div style="background:linear-gradient(135deg,#240000,#8b0000,#c1121f);padding:28px 32px;color:#fff;">
      <div style="font-size:14px;opacity:.82;margin-bottom:8px;">{{site_name}}</div>
      <h1 style="margin:0;font-size:28px;line-height:1.4;">رمز التحقق الخاص بك</h1>
    </div>
    <div style="padding:32px;background:#111;">
      <p style="margin:0 0 18px;color:#d7d7d7;font-size:16px;line-height:1.9;">مرحباً، تم استلام طلب <strong style="color:#fff">{{purpose_label}}</strong> للبريد <strong style="color:#fff">{{email}}</strong>.</p>
      <div style="margin:24px 0;padding:18px 24px;border-radius:18px;background:#190606;border:1px dashed #a30d17;text-align:center;">
        <div style="color:#ff5a64;font-size:13px;margin-bottom:8px;">رمز الاستخدام لمرة واحدة</div>
        <div style="font-size:36px;letter-spacing:8px;font-weight:900;color:#fff;">{{otp_code}}</div>
      </div>
      <p style="margin:0 0 10px;color:#cfcfcf;font-size:15px;line-height:1.9;">صلاحية الرمز: <strong style="color:#ff4b55">{{validity_minutes}} دقائق</strong>.</p>
      <p style="margin:0;color:#888;font-size:14px;line-height:1.8;">إذا لم تقم بهذا الطلب، يمكنك تجاهل هذه الرسالة بأمان.</p>
    </div>
  </div>
</div>
HTML;
}

function app_default_settings(): array
{
    return [
        'site_name' => 'مريم محمد',
        'site_tagline' => 'مساحة عربية مرحة للنشر والتفاعل والمقالات الإبداعية',
        'site_description' => 'منصة عربية احترافية للنشر والمقالات والملفات الشخصية مع توثيق مجاني عبر مراجعة الإدارة.',
        'verification_email_subject' => 'رمز التحقق - {{site_name}}',
        'verification_email_template' => app_default_email_template(),
    ];
}

function app_get_settings(): array
{
    $file = app_settings_file();
    app_ensure_file($file, app_default_settings());
    return array_replace_recursive(app_default_settings(), app_read_json($file, []));
}

function app_save_settings(array $settings): bool
{
    $merged = array_replace_recursive(app_default_settings(), $settings);
    return app_write_json(app_settings_file(), $merged);
}

function app_bootstrap(): void
{
    app_ensure_file(app_users_file(), [
        'users' => [
            [
                'id' => 1,
                'username' => 'admin',
                'display_name' => 'مدير الموقع',
                'email' => 'admin@example.com',
                'password' => password_hash('Admin@123456', PASSWORD_DEFAULT),
                'verified' => true,
                'email_verified' => true,
                'verification_requested' => false,
                'verification_method' => 'admin',
                'bio' => 'مرحباً بكم في لوحة الإدارة.',
                'avatar' => '',
                'role' => 'admin',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ],
        'next_username' => 2,
    ]);

    app_ensure_file(app_posts_file(), ['posts' => []]);
    app_ensure_file(app_otps_file(), []);
    app_ensure_file(app_verification_requests_file(), ['requests' => []]);
    app_ensure_file(app_settings_file(), app_default_settings());
    app_uploads_dir();
}

function app_normalize_user(array $user): array
{
    $normalized = array_merge([
        'id' => 0,
        'username' => '',
        'display_name' => '',
        'email' => '',
        'password' => '',
        'verified' => false,
        'email_verified' => false,
        'verification_requested' => false,
        'verification_method' => '',
        'bio' => '',
        'avatar' => '',
        'role' => 'user',
        'created_at' => '',
        'updated_at' => '',
        'verified_at' => '',
        'email_verified_at' => '',
        'verification_expires_at' => '',
        'wallet_phone' => null,
    ], $user);

    if (($normalized['role'] ?? 'user') === 'admin' && empty($normalized['email_verified'])) {
        $normalized['email_verified'] = true;
    }

    $normalized['verified'] = !empty($normalized['verified']);
    $normalized['email_verified'] = !empty($normalized['email_verified']);
    $normalized['verification_requested'] = !empty($normalized['verification_requested']);
    if ($normalized['verified'] && !empty($normalized['verification_expires_at']) && strtotime((string)$normalized['verification_expires_at']) !== false && strtotime((string)$normalized['verification_expires_at']) <= time()) {
        $normalized['verified'] = false;
        $normalized['verification_requested'] = false;
        $normalized['verification_method'] = '';
        $normalized['verification_expires_at'] = '';
    }

    return $normalized;
}

function app_get_users_data(): array
{
    app_bootstrap();
    $data = app_read_json(app_users_file(), ['users' => [], 'next_username' => 1]);
    $data['users'] = $data['users'] ?? [];
    $data['next_username'] = (int)($data['next_username'] ?? 1);

    $changed = false;
    foreach ($data['users'] as $index => $user) {
        $normalized = app_normalize_user($user);
        if ($normalized !== $user) {
            $data['users'][$index] = $normalized;
            $changed = true;
        }
    }

    if ($changed) {
        app_write_json(app_users_file(), $data);
    }

    return $data;
}

function app_save_users_data(array $data): bool
{
    return app_write_json(app_users_file(), $data);
}

function app_user_public(array $user): array
{
    $user = app_normalize_user($user);
    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'email' => $user['email'],
        'verified' => (bool)$user['verified'],
        'email_verified' => (bool)$user['email_verified'],
        'verification_requested' => (bool)$user['verification_requested'],
        'avatar' => $user['avatar'] ?? '',
        'bio' => $user['bio'] ?? '',
        'role' => $user['role'] ?? 'user',
        'created_at' => $user['created_at'] ?? '',
    ];
}

function app_find_user_by_id(int $id): ?array
{
    foreach (app_get_users_data()['users'] as $user) {
        if ((int)$user['id'] === $id) {
            return app_normalize_user($user);
        }
    }
    return null;
}

function app_find_user_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    foreach (app_get_users_data()['users'] as $user) {
        if (strtolower((string)($user['email'] ?? '')) === $email) {
            return app_normalize_user($user);
        }
    }
    return null;
}

function app_find_user_by_username(string $username): ?array
{
    $username = strtolower(trim($username));
    foreach (app_get_users_data()['users'] as $user) {
        if (strtolower((string)($user['username'] ?? '')) === $username) {
            return app_normalize_user($user);
        }
    }
    return null;
}

function app_find_user_by_identity(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $user = app_find_user_by_email($identifier);
        if ($user) {
            return $user;
        }
    }
    return app_find_user_by_username($identifier);
}

function app_validate_username(string $username): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9_\.]{3,20}$/', $username);
}

function app_generate_username(array &$data): string
{
    $next = (int)($data['next_username'] ?? 1);
    do {
        $candidate = 'user' . $next;
        $next++;
    } while (app_find_user_by_username($candidate));
    $data['next_username'] = $next;
    return $candidate;
}

function app_update_user(int $userId, array $fields): ?array
{
    $data = app_get_users_data();
    foreach ($data['users'] as &$user) {
        if ((int)$user['id'] === $userId) {
            foreach ($fields as $key => $value) {
                $user[$key] = $value;
            }
            $user['updated_at'] = date('Y-m-d H:i:s');
            $user = app_normalize_user($user);
            app_save_users_data($data);
            return $user;
        }
    }
    return null;
}

function app_update_user_by_email(string $email, array $fields): ?array
{
    $email = strtolower(trim($email));
    $data = app_get_users_data();
    foreach ($data['users'] as &$user) {
        if (strtolower((string)($user['email'] ?? '')) === $email) {
            foreach ($fields as $key => $value) {
                $user[$key] = $value;
            }
            $user['updated_at'] = date('Y-m-d H:i:s');
            $user = app_normalize_user($user);
            app_save_users_data($data);
            return $user;
        }
    }
    return null;
}

function app_prepare_registration(array $payload): array
{
    $email = strtolower(trim((string)($payload['email'] ?? '')));
    $displayName = trim((string)($payload['display_name'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    $providedPasswordHash = trim((string)($payload['password_hash'] ?? ''));
    $requestedUsername = trim((string)($payload['username'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'البريد الإلكتروني غير صالح'];
    }
    if ($displayName === '' || app_strlen($displayName) < 3) {
        return ['success' => false, 'error' => 'اسم العرض يجب أن يكون 3 أحرف على الأقل'];
    }
    if ($providedPasswordHash === '' && strlen($password) < 6) {
        return ['success' => false, 'error' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل'];
    }
    if ($requestedUsername !== '' && !app_validate_username($requestedUsername)) {
        return ['success' => false, 'error' => 'اسم المستخدم يجب أن يكون من 3 إلى 20 حرفاً أو رقماً أو _ أو .'];
    }

    $existingByEmail = app_find_user_by_email($email);
    if ($existingByEmail && !empty($existingByEmail['email_verified'])) {
        return ['success' => false, 'error' => 'البريد الإلكتروني مستخدم مسبقاً'];
    }
    if ($requestedUsername !== '') {
        $sameUsername = app_find_user_by_username($requestedUsername);
        if ($sameUsername && (!$existingByEmail || (int)$sameUsername['id'] !== (int)$existingByEmail['id'])) {
            return ['success' => false, 'error' => 'اسم المستخدم مستخدم مسبقاً'];
        }
    }

    return [
        'success' => true,
        'data' => [
            'email' => $email,
            'display_name' => $displayName,
            'username' => $requestedUsername,
            'password_hash' => $providedPasswordHash !== '' ? $providedPasswordHash : password_hash($password, PASSWORD_DEFAULT),
            'existing_user_id' => $existingByEmail['id'] ?? null,
        ],
    ];
}

function app_create_user(array $payload): array
{
    $prepared = app_prepare_registration($payload);
    if (!$prepared['success']) {
        return $prepared;
    }
    $registration = $prepared['data'];
    $email = $registration['email'];
    $displayName = $registration['display_name'];
    $requestedUsername = $registration['username'];
    $passwordHash = $registration['password_hash'];
    $existingByEmail = $registration['existing_user_id'] ? app_find_user_by_id((int)$registration['existing_user_id']) : null;

    $data = app_get_users_data();

    if ($existingByEmail && empty($existingByEmail['email_verified'])) {
        foreach ($data['users'] as &$user) {
            if ((int)$user['id'] === (int)$existingByEmail['id']) {
                if ($requestedUsername !== '' && strtolower($requestedUsername) !== strtolower((string)$user['username'])) {
                    $sameName = app_find_user_by_username($requestedUsername);
                    if ($sameName && (int)$sameName['id'] !== (int)$user['id']) {
                        return ['success' => false, 'error' => 'اسم المستخدم مستخدم مسبقاً'];
                    }
                    $user['username'] = $requestedUsername;
                }
                $user['display_name'] = $displayName;
                $user['password'] = $passwordHash;
                $user['email_verified'] = true;
                $user['verified'] = false;
                $user['verification_requested'] = false;
                $user['verification_method'] = '';
                $user['updated_at'] = date('Y-m-d H:i:s');
                $user = app_normalize_user($user);
                app_save_users_data($data);
                return ['success' => true, 'user' => $user, 'is_new' => false];
            }
        }
    }

    $maxId = 0;
    foreach ($data['users'] as $user) {
        $maxId = max($maxId, (int)($user['id'] ?? 0));
    }

    $username = $requestedUsername !== '' ? $requestedUsername : app_generate_username($data);

    $user = [
        'id' => $maxId + 1,
        'username' => $username,
        'display_name' => $displayName,
        'email' => $email,
        'password' => $passwordHash,
        'verified' => false,
        'email_verified' => true,
        'verification_requested' => false,
        'verification_method' => '',
        'bio' => '',
        'avatar' => '',
        'role' => 'user',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'email_verified_at' => date('Y-m-d H:i:s'),
    ];

    $data['users'][] = app_normalize_user($user);
    app_save_users_data($data);

    return ['success' => true, 'user' => app_normalize_user($user), 'is_new' => true];
}

function app_verify_login(string $identifier, string $password)
{
    $user = app_find_user_by_identity($identifier);
    if (!$user || !password_verify($password, (string)($user['password'] ?? ''))) {
        return false;
    }
    if (($user['role'] ?? 'user') !== 'admin' && empty($user['email_verified'])) {
        return false;
    }
    return $user;
}

function app_login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['role'] = $user['role'] ?? 'user';
}

function app_logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function app_clean_otps(array $records): array
{
    $now = time();
    return array_values(array_filter($records, static function ($item) use ($now) {
        return (($item['expires_at'] ?? 0) > $now) && empty($item['used']);
    }));
}

function app_get_otp_records(): array
{
    return app_clean_otps(app_read_json(app_otps_file(), []));
}

function app_save_otp_records(array $records): bool
{
    return app_write_json(app_otps_file(), array_values($records));
}

function app_save_otp(string $email, string $purpose, string $code, array $meta = []): void
{
    $email = strtolower(trim($email));
    $records = app_get_otp_records();
    $records = array_values(array_filter($records, static function ($item) use ($email, $purpose) {
        return !(strtolower((string)($item['email'] ?? '')) === $email && (string)($item['purpose'] ?? '') === $purpose);
    }));

    $records[] = [
        'email' => $email,
        'purpose' => $purpose,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'created_at' => time(),
        'expires_at' => time() + (APP_OTP_VALIDITY_MINUTES * 60),
        'attempts' => 0,
        'used' => false,
        'meta' => $meta,
    ];

    app_save_otp_records($records);
}

function app_recent_otp_exists(string $email, string $purpose): bool
{
    $email = strtolower(trim($email));
    foreach (app_get_otp_records() as $item) {
        if (strtolower((string)($item['email'] ?? '')) === $email && (string)($item['purpose'] ?? '') === $purpose) {
            return (time() - (int)($item['created_at'] ?? 0)) < APP_RESEND_COOLDOWN_SECONDS;
        }
    }
    return false;
}

function app_verify_otp(string $email, string $purpose, string $code): array
{
    $email = strtolower(trim($email));
    $code = trim($code);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'البريد الإلكتروني غير صالح'];
    }
    if (!preg_match('/^\d{4}$/', $code)) {
        return ['success' => false, 'error' => 'رمز التحقق يجب أن يكون 4 أرقام'];
    }

    $records = app_read_json(app_otps_file(), []);
    foreach ($records as $index => $item) {
        if (strtolower((string)($item['email'] ?? '')) !== $email || (string)($item['purpose'] ?? '') !== $purpose || !empty($item['used'])) {
            continue;
        }
        if ((int)($item['expires_at'] ?? 0) < time()) {
            return ['success' => false, 'error' => 'انتهت صلاحية الرمز'];
        }
        if ((int)($item['attempts'] ?? 0) >= APP_MAX_OTP_ATTEMPTS) {
            return ['success' => false, 'error' => 'تم تجاوز الحد المسموح من المحاولات'];
        }
        if (password_verify($code, (string)($item['code_hash'] ?? ''))) {
            $records[$index]['used'] = true;
            app_save_otp_records($records);
            return ['success' => true, 'meta' => $item['meta'] ?? []];
        }
        $records[$index]['attempts'] = ((int)($item['attempts'] ?? 0)) + 1;
        app_save_otp_records($records);
        return ['success' => false, 'error' => 'رمز التحقق غير صحيح'];
    }

    return ['success' => false, 'error' => 'لا يوجد طلب تحقق نشط'];
}

function app_smtp_read($socket): string
{
    $data = '';
    while ($line = fgets($socket, 515)) {
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return $data;
}

function app_smtp_command($socket, string $command, array $validCodes = [250]): array
{
    fwrite($socket, $command . "\r\n");
    $response = app_smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    return ['success' => in_array($code, $validCodes, true), 'response' => $response, 'code' => $code];
}

function app_mime_header(string $text): string
{
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function app_render_template(string $template, array $vars): string
{
    $map = [];
    foreach ($vars as $key => $value) {
        $map['{{' . $key . '}}'] = (string)$value;
    }
    return strtr($template, $map);
}

function app_send_email(string $to, string $subject, string $htmlBody, string $plainBody = ''): array
{
    $host = getenv('SMTP_HOST') ?: 'smtp.foundermail.mx';
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $username = getenv('SMTP_USERNAME') ?: 'otp@kruri-valverde.com';
    $password = getenv('SMTP_PASSWORD') ?: '1cb17d988c6547eb88a3f5dfc0ee1d4a';
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: $username;
    $fromName = getenv('SMTP_FROM_NAME') ?: 'UNITED';

    if ($plainBody === '') {
        $plainBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
    }

    $transport = $port === 465 ? 'ssl' : 'tcp';
    $socket = @stream_socket_client(
        $transport . '://' . $host . ':' . $port,
        $errno,
        $errstr,
        8,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return ['success' => false, 'error' => 'فشل الاتصال بخادم البريد: ' . $errstr . ' (' . $errno . ')'];
    }

    stream_set_timeout($socket, 8);

    $greeting = app_smtp_read($socket);
    if ((int)substr($greeting, 0, 3) !== 220) {
        fclose($socket);
        return ['success' => false, 'error' => 'خادم البريد رفض الاتصال'];
    }

    $ehlo = app_smtp_command($socket, 'EHLO localhost', [250]);
    if (!$ehlo['success']) {
        fclose($socket);
        return ['success' => false, 'error' => 'فشل EHLO'];
    }

    if ($port !== 465 && stripos($ehlo['response'], 'STARTTLS') !== false) {
        $tls = app_smtp_command($socket, 'STARTTLS', [220]);
        if (!$tls['success'] || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['success' => false, 'error' => 'تعذر تفعيل تشفير TLS'];
        }
        $ehlo = app_smtp_command($socket, 'EHLO localhost', [250]);
        if (!$ehlo['success']) {
            fclose($socket);
            return ['success' => false, 'error' => 'فشل EHLO بعد TLS'];
        }
    }

    $steps = [
        app_smtp_command($socket, 'AUTH LOGIN', [334]),
        app_smtp_command($socket, base64_encode($username), [334]),
        app_smtp_command($socket, base64_encode($password), [235]),
        app_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]),
        app_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]),
        app_smtp_command($socket, 'DATA', [354]),
    ];

    foreach ($steps as $step) {
        if (!$step['success']) {
            fclose($socket);
            return ['success' => false, 'error' => 'فشل الإرسال: ' . trim($step['response'])];
        }
    }

    $boundary = 'b_' . md5(uniqid((string)mt_rand(), true));
    $headers = [
        'From: ' . app_mime_header($fromName) . ' <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Subject: ' . app_mime_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Date: ' . date('r'),
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $plainBody . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $htmlBody . "\r\n\r\n";
    $message .= '--' . $boundary . "--\r\n";
    $message = preg_replace('/^\./m', '..', $message);

    fwrite($socket, $message . "\r\n.\r\n");
    $response = app_smtp_read($socket);
    $responseCode = (int) substr($response, 0, 3);
    app_smtp_command($socket, 'QUIT', [221]);
    fclose($socket);

    if ($responseCode !== 250) {
        return ['success' => false, 'error' => 'الخادم لم يقبل الرسالة: ' . trim($response)];
    }

    return ['success' => true, 'message' => 'تم إرسال الرسالة بنجاح'];
}

function app_purpose_label(string $purpose): string
{
    return match ($purpose) {
        'register' => 'تأكيد البريد الإلكتروني',
        'password_reset' => 'استعادة كلمة المرور',
        default => 'التحقق من البريد الإلكتروني',
    };
}

function app_send_otp_email(string $email, string $purpose, array $extra = []): array
{
    if (app_recent_otp_exists($email, $purpose)) {
        return ['success' => false, 'error' => 'تم إرسال رمز حديثاً، انتظر قليلاً ثم أعد المحاولة'];
    }

    $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

    $settings = app_get_settings();
    $vars = [
        'site_name' => $settings['site_name'],
        'otp_code' => $code,
        'validity_minutes' => APP_OTP_VALIDITY_MINUTES,
        'email' => $email,
        'purpose_label' => app_purpose_label($purpose),
    ];

    $subject = app_render_template((string)$settings['verification_email_subject'], $vars);
    $html = app_render_template((string)$settings['verification_email_template'], $vars);
    $plain = "{$settings['site_name']}\n" . app_purpose_label($purpose) . "\nرمز التحقق: {$code}\nالصلاحية: " . APP_OTP_VALIDITY_MINUTES . " دقائق";

    $send = app_send_email($email, $subject, $html, $plain);
    if (!$send['success']) {
        return $send;
    }

    app_save_otp($email, $purpose, $code, $extra);
    return ['success' => true, 'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني'];
}

function app_mark_email_verified(string $email): ?array
{
    return app_update_user_by_email($email, [
        'email_verified' => true,
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);
}

function app_generate_post_permalink(array $posts): string
{
    $used = [];
    foreach ($posts as $post) {
        if (!empty($post['permalink'])) {
            $used[(string)$post['permalink']] = true;
        }
    }

    for ($i = 0; $i < 50; $i++) {
        $candidate = (string) random_int(10000, 99999);
        if (empty($used[$candidate])) {
            return $candidate;
        }
    }

    return (string)(100000 + count($posts) + 1);
}

function app_normalize_post(array $post, array $allPosts = []): array
{
    if (empty($post['permalink'])) {
        $post['permalink'] = app_generate_post_permalink($allPosts ?: [$post]);
    }
    $post['views'] = (int)($post['views'] ?? 0);
    $post['likes'] = array_values($post['likes'] ?? []);
    $post['comments'] = array_values($post['comments'] ?? []);
    return $post;
}

function app_get_posts_data(): array
{
    app_bootstrap();
    $data = app_read_json(app_posts_file(), ['posts' => []]);
    $data['posts'] = $data['posts'] ?? [];

    $changed = false;
    foreach ($data['posts'] as $index => $post) {
        $normalized = app_normalize_post($post, $data['posts']);
        if ($normalized !== $post) {
            $data['posts'][$index] = $normalized;
            $changed = true;
        }
    }

    if ($changed) {
        app_write_json(app_posts_file(), $data);
    }

    return $data;
}

function app_save_posts_data(array $data): bool
{
    return app_write_json(app_posts_file(), $data);
}

function app_sanitize_post_html(string $html): string
{
    $html = trim($html);
    $html = preg_replace('/<\s*(script|style)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? '';
    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><blockquote><code><pre><h1><h2><h3><h4><h5><h6><a><img><div><span><section><article><table><thead><tbody><tr><th><td><hr><figure><figcaption><video><audio><source><iframe>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^\2]*\2/i', '$1="#"', $clean) ?? '';
    $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*data:text\/html[^\2]*\2/i', '$1="#"', $clean) ?? '';
    return trim((string)$clean);
}

function app_find_post_by_id(int $postId): ?array
{
    foreach (app_get_posts_data()['posts'] as $post) {
        if ((int)($post['id'] ?? 0) === $postId) {
            return $post;
        }
    }
    return null;
}

function app_find_post_by_permalink(string $permalink): ?array
{
    $permalink = trim($permalink, "/ ");
    foreach (app_get_posts_data()['posts'] as $post) {
        if ((string)($post['permalink'] ?? '') === $permalink) {
            return $post;
        }
    }
    return null;
}

function app_add_post(string $title, string $content, string $image, int $authorId, string $authorUsername): int
{
    $data = app_get_posts_data();
    $posts = $data['posts'] ?? [];
    $nextId = 1;
    foreach ($posts as $post) {
        $nextId = max($nextId, ((int)$post['id']) + 1);
    }

    $posts[] = [
        'id' => $nextId,
        'permalink' => app_generate_post_permalink($posts),
        'title' => trim($title),
        'content' => app_sanitize_post_html($content),
        'image' => trim($image),
        'author_id' => $authorId,
        'author_username' => $authorUsername,
        'date' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'views' => 0,
        'likes' => [],
        'comments' => [],
    ];
    $data['posts'] = $posts;
    app_save_posts_data($data);
    return $nextId;
}

function app_delete_post(int $postId, int $userId): bool
{
    $data = app_get_posts_data();
    $currentUser = app_find_user_by_id($userId);
    $posts = $data['posts'] ?? [];
    foreach ($posts as $index => $post) {
        if ((int)$post['id'] === $postId && (((int)$post['author_id'] === $userId) || (($currentUser['role'] ?? '') === 'admin'))) {
            array_splice($posts, $index, 1);
            $data['posts'] = $posts;
            app_save_posts_data($data);
            return true;
        }
    }
    return false;
}

function app_add_comment(int $postId, int $authorId, string $authorUsername, string $content): bool
{
    $data = app_get_posts_data();
    foreach ($data['posts'] as &$post) {
        if ((int)$post['id'] === $postId) {
            $post['comments'][] = [
                'id' => count($post['comments']) + 1,
                'author_id' => $authorId,
                'author_username' => $authorUsername,
                'content' => trim(strip_tags($content)),
                'date' => date('Y-m-d H:i:s'),
            ];
            app_save_posts_data($data);
            return true;
        }
    }
    return false;
}

function app_toggle_like(int $postId, int $userId): array
{
    $data = app_get_posts_data();
    foreach ($data['posts'] as &$post) {
        if ((int)$post['id'] === $postId) {
            $likes = $post['likes'] ?? [];
            $key = array_search($userId, $likes, true);
            if ($key !== false) {
                unset($likes[$key]);
                $post['likes'] = array_values($likes);
                app_save_posts_data($data);
                return ['action' => 'unliked', 'count' => count($post['likes'])];
            }
            $likes[] = $userId;
            $post['likes'] = array_values(array_unique($likes));
            app_save_posts_data($data);
            return ['action' => 'liked', 'count' => count($post['likes'])];
        }
    }
    return ['action' => 'error', 'count' => 0];
}

function app_get_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/index.php'), '/\\');
    return $scheme . '://' . $host . ($path ? $path . '/' : '/');
}

function app_get_verification_requests(): array
{
    app_bootstrap();
    return app_read_json(app_verification_requests_file(), ['requests' => []]);
}

function app_save_verification_requests(array $data): bool
{
    return app_write_json(app_verification_requests_file(), $data);
}

function app_find_verification_request_by_id(string $requestId): ?array
{
    $requests = app_get_verification_requests()['requests'] ?? [];
    foreach ($requests as $request) {
        if ((string)($request['request_id'] ?? '') === $requestId) {
            return $request;
        }
    }
    return null;
}

function app_find_active_verification_request_by_user(int $userId): ?array
{
    $requests = app_get_verification_requests()['requests'] ?? [];
    foreach (array_reverse($requests) as $request) {
        if ((int)($request['user_id'] ?? 0) === $userId && in_array((string)($request['status'] ?? ''), ['pending', 'payment_pending'], true)) {
            return $request;
        }
    }
    return null;
}

function app_create_verification_request(int $userId, array $meta = []): array
{
    $user = app_find_user_by_id($userId);
    if (!$user) {
        return ['success' => false, 'error' => 'المستخدم غير موجود'];
    }
    if (!empty($user['verified'])) {
        return ['success' => false, 'error' => 'الحساب موثق بالفعل'];
    }
    if (app_find_active_verification_request_by_user($userId)) {
        return ['success' => false, 'error' => 'يوجد طلب توثيق قيد المراجعة بالفعل'];
    }

    $data = app_get_verification_requests();
    $data['requests'][] = [
        'request_id' => 'vr_' . bin2hex(random_bytes(6)),
        'user_id' => $userId,
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'email' => $user['email'],
        'status' => 'pending',
        'meta' => $meta,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    app_save_verification_requests($data);
    app_update_user($userId, ['verification_requested' => true]);
    return ['success' => true, 'message' => 'تم إرسال طلب التوثيق إلى الإدارة'];
}

function app_create_paid_verification_request(int $userId, string $orderId, array $meta = []): array
{
    $user = app_find_user_by_id($userId);
    if (!$user) return ['success' => false, 'error' => 'المستخدم غير موجود'];
    if (empty($user['email_verified'])) return ['success' => false, 'error' => 'يجب تأكيد البريد الإلكتروني أولاً'];
    if (!empty($user['verified'])) return ['success' => false, 'error' => 'الحساب موثق بالفعل'];
    if (app_find_active_verification_request_by_user($userId)) return ['success' => false, 'error' => 'يوجد طلب توثيق نشط بالفعل'];

    $data = app_get_verification_requests();
    $requestId = 'vr_' . bin2hex(random_bytes(6));
    $data['requests'][] = [
        'request_id' => $requestId,
        'user_id' => $userId,
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'email' => $user['email'],
        'status' => 'payment_pending',
        'amount' => 2000,
        'currency' => 'IQD',
        'billing_period' => 'monthly',
        'order_id' => $orderId,
        'meta' => $meta,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    app_save_verification_requests($data);
    app_update_user($userId, ['verification_requested' => true]);
    return ['success' => true, 'request_id' => $requestId, 'message' => 'تم إنشاء طلب التوثيق والدفع'];
}

function app_update_verification_request(string $requestId, array $fields): ?array
{
    $data = app_get_verification_requests();
    foreach ($data['requests'] as &$request) {
        if ((string)($request['request_id'] ?? '') === $requestId) {
            foreach ($fields as $key => $value) {
                $request[$key] = $value;
            }
            $request['updated_at'] = date('Y-m-d H:i:s');
            app_save_verification_requests($data);
            return $request;
        }
    }
    return null;
}

function app_approve_verification_request(string $requestId, int $adminId = 0): ?array
{
    $request = app_find_verification_request_by_id($requestId);
    if (!$request) {
        return null;
    }
    app_update_verification_request($requestId, [
        'status' => 'approved',
        'approved_at' => date('Y-m-d H:i:s'),
        'approved_by' => $adminId,
    ]);
    app_mark_user_as_verified((int)$request['user_id'], ['verification_method' => (($request['status'] ?? '') === 'approved' && !empty($request['amount'])) ? 'zaincash_monthly' : 'admin']);
    return app_find_verification_request_by_id($requestId);
}

function app_complete_paid_verification(string $requestId, array $paymentMeta = []): ?array
{
    $request = app_find_verification_request_by_id($requestId);
    if (!$request || !in_array((string)($request['status'] ?? ''), ['payment_pending', 'pending'], true)) return null;
    $expires = date('Y-m-d H:i:s', strtotime('+1 month'));
    app_update_verification_request($requestId, [
        'status' => 'approved',
        'payment_status' => 'paid',
        'approved_at' => date('Y-m-d H:i:s'),
        'verification_expires_at' => $expires,
        'payment' => $paymentMeta,
    ]);
    app_mark_user_as_verified((int)$request['user_id'], [
        'verification_method' => 'zaincash_monthly',
        'verification_expires_at' => $expires,
    ]);
    return app_find_verification_request_by_id($requestId);
}

function app_reject_verification_request(string $requestId, int $adminId = 0): ?array
{
    $request = app_find_verification_request_by_id($requestId);
    if (!$request) {
        return null;
    }
    app_update_verification_request($requestId, [
        'status' => 'rejected',
        'rejected_at' => date('Y-m-d H:i:s'),
        'rejected_by' => $adminId,
    ]);
    app_update_user((int)$request['user_id'], ['verification_requested' => false]);
    return app_find_verification_request_by_id($requestId);
}

function app_mark_user_as_verified(int $userId, array $meta = []): ?array
{
    return app_update_user($userId, [
        'verified' => true,
        'verification_requested' => false,
        'verification_method' => $meta['verification_method'] ?? 'free_request',
        'verified_at' => date('Y-m-d H:i:s'),
        'verification_expires_at' => $meta['verification_expires_at'] ?? '',
        'wallet_phone' => $meta['wallet_phone'] ?? null,
    ]);
}
