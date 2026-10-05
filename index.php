<?php
require_once __DIR__ . '/app_core.php';
app_bootstrap();
$settings = app_get_settings();

function respond(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function current_user(): ?array {
    return !empty($_SESSION['logged_in']) ? app_find_user_by_id((int)$_SESSION['user_id']) : null;
}

function post_public(array $post, bool $detail = false): array {
    $author = app_find_user_by_id((int)($post['author_id'] ?? 0));
    $data = $post;
    $data['likes_count'] = count($post['likes'] ?? []);
    $data['comments_count'] = count($post['comments'] ?? []);
    $data['is_liked'] = !empty($_SESSION['user_id']) ? in_array((int)$_SESSION['user_id'], $post['likes'] ?? [], true) : false;
    $data['author_avatar'] = $author['avatar'] ?? '';
    $data['author_display_name'] = $author['display_name'] ?? ($post['author_username'] ?? '');
    $data['author_verified'] = !empty($author['verified']);
    $data['permalink'] = (string)($post['permalink'] ?? '');
    if (!$detail) {
        unset($data['likes']);
    } else {
        $data['comments'] = array_map(static function ($comment) {
            $commentAuthor = app_find_user_by_id((int)($comment['author_id'] ?? 0));
            $comment['author_avatar'] = $commentAuthor['avatar'] ?? '';
            $comment['author_verified'] = !empty($commentAuthor['verified']);
            $comment['author_display_name'] = $commentAuthor['display_name'] ?? ($comment['author_username'] ?? '');
            return $comment;
        }, $data['comments'] ?? []);
        unset($data['likes']);
    }
    return $data;
}

if (($_GET['action'] ?? '') === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = current_user();
    if (!$user) respond(['success' => false, 'error' => 'تسجيل الدخول مطلوب']);
    if (empty($_FILES['image'])) respond(['success' => false, 'error' => 'لم يتم اختيار صورة']);
    $file = $_FILES['image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) respond(['success' => false, 'error' => 'فشل رفع الصورة']);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) respond(['success' => false, 'error' => 'النوع غير مدعوم']);
    $name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = app_uploads_dir() . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['success' => false, 'error' => 'تعذر حفظ الصورة']);
    respond(['success' => true, 'url' => app_get_base_url() . 'uploads/' . $name]);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput ?: '', true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';
if ($action) {
    if ($action === 'check') {
        $user = current_user();
        respond(['success' => true, 'logged_in' => (bool)$user, 'settings' => ['site_name' => $settings['site_name'], 'site_tagline' => $settings['site_tagline'], 'site_description' => $settings['site_description']], 'user' => $user ? app_user_public($user) : null]);
    }

    if ($action === 'login') {
        $identifier = trim((string)($input['email'] ?? $input['identifier'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $user = app_verify_login($identifier, $password);
        if (!$user) respond(['success' => false, 'error' => 'بيانات الدخول غير صحيحة']);
        app_login_user($user);
        respond(['success' => true, 'user' => app_user_public($user)]);
    }

    if ($action === 'registerStart') {
        $prepared = app_prepare_registration([
            'display_name' => trim((string)($input['display_name'] ?? '')),
            'username' => trim((string)($input['username'] ?? '')),
            'email' => strtolower(trim((string)($input['email'] ?? ''))),
            'password' => (string)($input['password'] ?? ''),
        ]);
        if (!$prepared['success']) respond($prepared);
        $_SESSION['pending_registration'] = $prepared['data'];
        $_SESSION['pending_registration']['created_at'] = time();
        $email = $_SESSION['pending_registration']['email'];
        $sent = app_send_otp_email($email, 'register');
        if (!$sent['success']) {
            unset($_SESSION['pending_registration']);
            respond($sent);
        }
        respond(['success' => true, 'message' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني']);
    }

    if ($action === 'registerVerify') {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $otp = trim((string)($input['otp'] ?? ''));
        $pending = $_SESSION['pending_registration'] ?? null;
        if (!is_array($pending) || strtolower((string)($pending['email'] ?? '')) !== $email) respond(['success' => false, 'error' => 'انتهت جلسة التسجيل، أعد المحاولة']);
        if ((int)($pending['created_at'] ?? 0) + 900 < time()) { unset($_SESSION['pending_registration']); respond(['success' => false, 'error' => 'انتهت صلاحية جلسة التسجيل']); }
        $verified = app_verify_otp($email, 'register', $otp);
        if (!$verified['success']) respond($verified);
        $created = app_create_user([
            'display_name' => $pending['display_name'],
            'username' => $pending['username'],
            'email' => $pending['email'],
            'password_hash' => $pending['password_hash'],
        ]);
        if (!$created['success']) { unset($_SESSION['pending_registration']); respond($created); }
        app_mark_email_verified($email);
        $created['user'] = app_find_user_by_email($email);
        unset($_SESSION['pending_registration']);
        app_login_user($created['user']);
        respond(['success' => true, 'message' => 'تم التحقق وإنشاء الحساب بنجاح', 'user' => app_user_public($created['user'])]);
    }

    if ($action === 'logout') {
        app_logout_user();
        respond(['success' => true]);
    }

    if ($action === 'resetPassword') {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $newPassword = (string)($input['new_password'] ?? '');
        if (strlen($newPassword) < 6) respond(['success' => false, 'error' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل']);
        if (($_SESSION['otp_verified_email'] ?? '') !== $email || ($_SESSION['otp_verified_purpose'] ?? '') !== 'password_reset' || ((int)($_SESSION['otp_verified_at'] ?? 0) + 900 < time())) respond(['success' => false, 'error' => 'يجب التحقق من رمز البريد أولاً']);
        $user = app_update_user_by_email($email, ['password' => password_hash($newPassword, PASSWORD_DEFAULT)]);
        if (!$user) respond(['success' => false, 'error' => 'الحساب غير موجود']);
        unset($_SESSION['otp_verified_email'], $_SESSION['otp_verified_purpose'], $_SESSION['otp_verified_at']);
        respond(['success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح']);
    }

    if ($action === 'updateProfile') {
        $user = current_user();
        if (!$user) respond(['success' => false, 'error' => 'تسجيل الدخول مطلوب']);
        $displayName = trim((string)($input['display_name'] ?? $user['display_name']));
        $username = trim((string)($input['username'] ?? $user['username']));
        $bio = trim((string)($input['bio'] ?? $user['bio']));
        $avatar = trim((string)($input['avatar'] ?? $user['avatar']));
        if (app_strlen($displayName) < 3) respond(['success' => false, 'error' => 'اسم العرض قصير']);
        if (!app_validate_username($username)) respond(['success' => false, 'error' => 'اسم المستخدم غير صالح']);
        $same = app_find_user_by_username($username);
        if ($same && (int)$same['id'] !== (int)$user['id']) respond(['success' => false, 'error' => 'اسم المستخدم مستخدم مسبقاً']);
        $updated = app_update_user((int)$user['id'], ['display_name' => $displayName, 'username' => $username, 'bio' => $bio, 'avatar' => $avatar]);
        $_SESSION['username'] = $updated['username'];
        $_SESSION['display_name'] = $updated['display_name'];
        respond(['success' => true, 'user' => app_user_public($updated)]);
    }

    if ($action === 'changePassword') {
        $user = current_user();
        if (!$user) respond(['success' => false, 'error' => 'تسجيل الدخول مطلوب']);
        $current = (string)($input['current_password'] ?? '');
        $new = (string)($input['new_password'] ?? '');
        if (!password_verify($current, $user['password'])) respond(['success' => false, 'error' => 'كلمة المرور الحالية غير صحيحة']);
        if (strlen($new) < 6) respond(['success' => false, 'error' => 'كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل']);
        app_update_user((int)$user['id'], ['password' => password_hash($new, PASSWORD_DEFAULT)]);
        respond(['success' => true, 'message' => 'تم تحديث كلمة المرور']);
    }

    if ($action === 'getPosts') {
        $posts = array_reverse(app_get_posts_data()['posts'] ?? []);
        respond(['success' => true, 'posts' => array_map(fn($post) => post_public($post), $posts)]);
    }

    if ($action === 'getPost') {
        $id = (int)($input['id'] ?? 0);
        $data = app_get_posts_data();
        foreach ($data['posts'] as &$post) {
            if ((int)$post['id'] === $id) {
                $post['views'] = (int)($post['views'] ?? 0) + 1;
                app_save_posts_data($data);
                respond(['success' => true, 'post' => post_public($post, true)]);
            }
        }
        respond(['success' => false, 'error' => 'المقال غير موجود']);
    }

    if ($action === 'getPostByPermalink') {
        $permalink = trim((string)($input['permalink'] ?? ''));
        $data = app_get_posts_data();
        foreach ($data['posts'] as &$post) {
            if ((string)($post['permalink'] ?? '') === $permalink) {
                $post['views'] = (int)($post['views'] ?? 0) + 1;
                app_save_posts_data($data);
                respond(['success' => true, 'post' => post_public($post, true)]);
            }
        }
        respond(['success' => false, 'error' => 'المقال غير موجود']);
    }

    if ($action === 'addPost') {
        $user = current_user();
        if (!$user) respond(['success' => false, 'error' => 'تسجيل الدخول مطلوب']);
        $title = trim((string)($input['title'] ?? ''));
        $content = trim((string)($input['content'] ?? ''));
        $image = trim((string)($input['image'] ?? ''));
        if ($title === '' || $content === '') respond(['success' => false, 'error' => 'العنوان والمحتوى مطلوبان']);
        $id = app_add_post($title, $content, $image, (int)$user['id'], (string)$user['username']);
        $created = app_find_post_by_id($id);
        respond(['success' => true, 'post_id' => $id, 'permalink' => $created['permalink'] ?? '']);
    }

    if ($action === 'deletePost') {
        $user = current_user();
        if (!$user) respond(['success' => false, 'error' => 'تسجيل الدخول مطلوب']);
        respond(['success' => app_delete_post((int)($input['post_id'] ?? 0), (int)$user['id'])]);
    }

    if ($action === 'addComment') {
        $user = current_user();
        if (!$user) respond(['success' => false, 'error' => 'تسجيل الدخول مطلوب']);
        app_add_comment((int)($input['post_id'] ?? 0), (int)$user['id'], (string)$user['username'], (string)($input['content'] ?? ''));
        respond(['success' => true]);
    }

    if ($action === 'like') {
        $user = current_user();
        if (!$user) respond(['success' => false, 'error' => 'تسجيل الدخول مطلوب']);
        $like = app_toggle_like((int)($input['post_id'] ?? 0), (int)$user['id']);
        respond(['success' => true] + $like);
    }

    if ($action === 'getUser') {
        $username = trim((string)($input['username'] ?? ''));
        $user = app_find_user_by_username($username);
        if (!$user) respond(['success' => false, 'error' => 'المستخدم غير موجود']);
        respond(['success' => true, 'user' => app_user_public($user)]);
    }

    if ($action === 'createVerificationRequest') {
        $user = current_user();
        if (!$user) respond(['success' => false, 'error' => 'تسجيل الدخول مطلوب']);
        if (empty($user['email_verified'])) respond(['success' => false, 'error' => 'يجب تأكيد البريد الإلكتروني أولاً']);
        if (!empty($user['verified'])) respond(['success' => false, 'error' => 'الحساب موثق بالفعل']);
        $orderId = 'UNITED-VER-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $result = app_create_paid_verification_request((int)$user['id'], $orderId, ['source' => 'site']);
        if (!$result['success']) respond($result);
        $paymentUrl = rtrim(app_get_base_url(), '/') . '/zaincash.php?action=pay&request_id=' . rawurlencode($result['request_id']);
        respond(['success' => true, 'message' => 'تم تجهيز طلب الدفع', 'payment_url' => $paymentUrl, 'amount' => 2000, 'currency' => 'IQD', 'billing_period' => 'monthly']);
    }

    respond(['success' => false, 'error' => 'إجراء غير معروف']);
}

$initialPermalink = '';
$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '', '/');
$selfDir = trim(dirname($_SERVER['PHP_SELF'] ?? '/index.php'), '/');
if ($selfDir !== '' && str_starts_with($path, $selfDir)) {
    $path = trim(substr($path, strlen($selfDir)), '/');
}
if ($path !== '' && preg_match('/^\d{4,10}$/', $path)) {
    $initialPermalink = $path;
}
if (isset($_GET['post']) && preg_match('/^\d{4,10}$/', (string)$_GET['post'])) {
    $initialPermalink = (string)$_GET['post'];
}
$basePath = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/index.php'), '/\\');
if ($basePath === '/') $basePath = '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($settings['site_name']) ?></title>
  <meta name="description" content="<?= htmlspecialchars($settings['site_description']) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#fff8f2;--bg2:#fff3ea;--card:#ffffff;--card2:#fffaf7;--text:#1f2937;--muted:#6b7280;--line:rgba(249,115,22,.16);--pri:#f97316;--pri2:#ea580c;--sec:#ec4899;--third:#8b5cf6;--ok:#10b981;--warn:#f59e0b;--danger:#ef4444;--shadow:0 24px 60px rgba(31,41,55,.08)}
    *{box-sizing:border-box}html,body{margin:0}body{font-family:'Cairo',sans-serif;background:radial-gradient(circle at top right,#ffe2c7 0,#fff1e3 24%,#fff8f2 52%,#fffdfb 100%);color:var(--text)}a{text-decoration:none;color:inherit}button,input,textarea{font-family:inherit}
    body::before,body::after{content:'';position:fixed;border-radius:50%;pointer-events:none;filter:blur(70px);opacity:.38;z-index:-1}body::before{width:280px;height:280px;background:#fdba74;top:-70px;right:-40px}body::after{width:260px;height:260px;background:#f9a8d4;bottom:-80px;left:-40px}
    .wrap{max-width:1240px;margin:auto;padding:26px 18px 44px}.top{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;padding:6px 0 18px}.brand{display:flex;align-items:center;gap:16px}.brand-badge{width:66px;height:66px;border-radius:22px;background:linear-gradient(135deg,var(--pri),var(--sec));display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;box-shadow:0 14px 30px rgba(249,115,22,.24)}.brand h1{margin:0;font-size:34px;font-weight:900}.brand p{margin:8px 0 0;color:var(--muted)}.nav{display:flex;gap:10px;flex-wrap:wrap}.btn,.ghost,.soft,.icon-btn{border:none;border-radius:18px;cursor:pointer;transition:.22s ease}.btn{background:linear-gradient(135deg,var(--pri),var(--sec));color:#fff;padding:13px 20px;font-weight:800;box-shadow:0 12px 30px rgba(236,72,153,.18)}.btn:hover,.ghost:hover,.soft:hover{transform:translateY(-1px)}.ghost{background:#fff;border:1px solid var(--line);color:var(--text);padding:13px 18px}.soft{background:linear-gradient(135deg,rgba(249,115,22,.10),rgba(236,72,153,.10));border:1px solid rgba(236,72,153,.12);color:#9a3412;padding:13px 18px}.icon-btn{width:46px;height:46px;background:#fff;border:1px solid var(--line);color:var(--text)}
    .hero{display:grid;grid-template-columns:1.2fr .8fr;gap:18px;margin:6px 0 26px}.hero-card,.hero-side{background:linear-gradient(180deg,#ffffff,#fffaf7);border:1px solid var(--line);border-radius:32px;box-shadow:var(--shadow)}.hero-card{padding:28px;position:relative;overflow:hidden}.hero-card::before{content:'';position:absolute;left:-60px;bottom:-60px;width:180px;height:180px;border-radius:40px;background:linear-gradient(135deg,rgba(249,115,22,.14),rgba(236,72,153,.06));transform:rotate(18deg)}.hero-card h2{margin:0 0 12px;font-size:40px;line-height:1.2}.hero-card p{margin:0 0 18px;color:var(--muted);line-height:1.9;max-width:780px}.hero-side{padding:22px;display:flex;flex-direction:column;justify-content:space-between;gap:14px}.stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.stat{padding:16px;border-radius:22px;background:#fff7ed;border:1px solid #fed7aa}.stat b{display:block;font-size:26px;margin-bottom:6px;color:#9a3412}.stat span{color:#7c2d12;font-size:13px}
    .content{display:grid;grid-template-columns:minmax(0,1fr);gap:22px;max-width:920px;margin:0 auto}.toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.search{flex:1;min-width:240px}.search input,.field input,.field textarea{width:100%;padding:15px 16px;border-radius:18px;border:1px solid rgba(249,115,22,.18);background:#fff;color:#111827;outline:none;transition:.2s}.search input:focus,.field input:focus,.field textarea:focus{border-color:rgba(236,72,153,.36);box-shadow:0 0 0 4px rgba(249,115,22,.10)}.field textarea{min-height:150px;resize:vertical}
    .feed{display:flex;flex-direction:column;gap:18px}.post{padding:24px;background:linear-gradient(180deg,#ffffff,#fffaf7);border:1px solid var(--line);border-radius:30px;box-shadow:var(--shadow)}.post-head,.comment-head{display:flex;gap:12px;align-items:center}.avatar{width:54px;height:54px;border-radius:50%;object-fit:cover;background:#fde7cf;border:2px solid rgba(249,115,22,.10)}.meta{flex:1}.meta b{display:flex;align-items:center;gap:7px}.muted{color:var(--muted);font-size:13px;line-height:1.8}.tag{display:inline-flex;align-items:center;gap:6px;background:rgba(249,115,22,.10);color:#9a3412;padding:8px 12px;border-radius:999px;font-size:13px;border:1px solid rgba(249,115,22,.18)}.post h2{margin:16px 0 10px;font-size:28px}.excerpt,.article .body,.profile p{color:#4b5563;line-height:2}.cover{width:100%;border-radius:24px;max-height:420px;object-fit:cover;margin:14px 0}.row{display:flex;gap:10px;flex-wrap:wrap}.row.spread{justify-content:space-between;align-items:center}.reactions{display:flex;gap:10px;flex-wrap:wrap}.badge,.mini-badge{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:#fff;border:1px solid var(--line);font-size:13px}.verified{width:18px;height:18px;display:inline-flex}
    .article,.profile{padding:30px;background:linear-gradient(180deg,#ffffff,#fffaf7);border:1px solid var(--line);border-radius:32px;box-shadow:var(--shadow)}.article h1,.profile h2{margin:14px 0 8px;font-size:34px}.article .body h1,.article .body h2,.article .body h3,.article .body h4{color:#111827;margin:18px 0 12px}.article .body a{color:#db2777}.article .body img{max-width:100%;border-radius:20px}.article .body pre{background:#1f2937;color:#fff;padding:16px;border-radius:18px;overflow:auto;direction:ltr;text-align:left}.article .body code{background:#fff1e8;padding:2px 7px;border-radius:8px}.article .body table{width:100%;border-collapse:collapse;margin:16px 0;background:#fff;border-radius:18px;overflow:hidden}.article .body th,.article .body td{border:1px solid #fde0cc;padding:10px;text-align:right}.article .body iframe,.article .body video,.article .body audio{max-width:100%;border-radius:20px}.comments{margin-top:24px;display:flex;flex-direction:column;gap:14px}.comment{padding:16px;border-radius:22px;background:#fff7ed;border:1px solid #fde2cc}.comment-input{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.comment-input input{flex:1;min-width:220px}
    .profile-top{display:flex;gap:20px;align-items:center;flex-wrap:wrap}.profile-top .avatar{width:112px;height:112px}.profile-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:18px}.mini{padding:16px;border-radius:20px;background:#fff7ed;border:1px solid #fde2cc}.mini b{display:block;font-size:24px;color:#9a3412}
    .field{margin-bottom:14px}.field label{display:block;margin-bottom:8px;color:#374151;font-size:14px}.modal{position:fixed;inset:0;background:rgba(15,23,42,.32);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:20px;z-index:99}.modal.show{display:flex}.card{width:min(100%,650px);max-height:92vh;overflow:auto;background:linear-gradient(180deg,#ffffff,#fffaf7);border:1px solid rgba(249,115,22,.16);border-radius:32px;padding:30px;box-shadow:var(--shadow)}.card h2{margin:0 0 10px;font-size:36px}.close{float:left;border:none;background:transparent;color:#374151;font-size:30px;cursor:pointer}.step{display:none}.step.active{display:block}.step small{display:block;color:#6b7280;font-size:15px;margin-bottom:14px}.status{margin-top:14px;padding:14px 16px;border-radius:18px;display:none;line-height:1.8}.status.show{display:block}.status.ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.22);color:#047857}.status.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.22);color:#b91c1c}.status.info{background:rgba(249,115,22,.10);border:1px solid rgba(249,115,22,.22);color:#c2410c}
    .empty{padding:44px;text-align:center;background:linear-gradient(180deg,#ffffff,#fffaf7);border:1px solid var(--line);border-radius:30px;box-shadow:var(--shadow)}.hide{display:none!important}.link{color:#db2777;cursor:pointer}.otp{display:flex;justify-content:center;gap:10px;direction:ltr;flex-wrap:nowrap;margin:18px 0}.otp input{width:54px;height:62px;text-align:center;font-size:25px;font-weight:800;border-radius:18px;border:1px solid rgba(249,115,22,.18);background:#fff;color:#111827}.otp-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px}.tip{background:#fff7ed;border:1px dashed #fdba74;padding:14px 16px;border-radius:18px;color:#9a3412;line-height:1.8;margin-top:12px}.pill-pending{display:inline-flex;align-items:center;gap:8px;background:rgba(245,158,11,.12);color:#92400e;border:1px solid rgba(245,158,11,.2);padding:10px 14px;border-radius:999px;font-size:13px;font-weight:700}
    @media(max-width:980px){.hero{grid-template-columns:1fr}.content{max-width:100%}.profile-grid{grid-template-columns:1fr 1fr}}@media(max-width:640px){.wrap{padding:14px}.brand{align-items:flex-start}.brand-badge{width:58px;height:58px;border-radius:18px}.brand h1{font-size:28px}.hero-card h2{font-size:30px}.top{align-items:flex-start;flex-direction:column}.nav{width:100%}.nav .btn,.nav .ghost,.nav .soft{flex:1}.post,.article,.profile,.card,.hero-card,.hero-side,.empty{border-radius:24px}.post h2{font-size:22px}.article h1,.profile h2{font-size:28px}.profile-grid,.otp-actions,.stat-grid{grid-template-columns:1fr}.card h2{font-size:30px}.otp{gap:8px;justify-content:space-between}.otp input{width:46px;height:56px;font-size:22px}}
  <style>
:root{--bg:#070707;--bg2:#120506;--card:#111111;--card2:#151515;--text:#f4f4f4;--muted:#9a9a9a;--line:rgba(184,20,30,.32);--pri:#8f0012;--pri2:#c1121f;--sec:#ef233c;--third:#7f1d1d;--ok:#22c55e;--warn:#f59e0b;--danger:#ef4444;--shadow:0 24px 70px rgba(0,0,0,.48)}
html{background:#070707}body{background:radial-gradient(circle at 85% 0,#350507 0,#160809 22%,#080808 58%,#050505 100%);color:var(--text)}body::before{background:#9b111e;opacity:.12}body::after{background:#4b0b12;opacity:.12}.brand-badge{background:#050505;border:1px solid #72111a;box-shadow:0 14px 34px rgba(180,0,20,.28);overflow:hidden}.brand-badge img{width:100%;height:100%;object-fit:cover;display:block}.brand h1{color:#fff;text-shadow:0 0 24px rgba(220,20,40,.24)}.brand p{color:#aaa}.ghost{background:#111;color:#eee;border-color:var(--line)}.btn{background:linear-gradient(135deg,#5d000b,#a90014,#e11d2e);box-shadow:0 12px 30px rgba(180,0,20,.24)}.soft{background:linear-gradient(135deg,rgba(120,0,12,.22),rgba(220,20,40,.10));border-color:rgba(220,20,40,.22);color:#ff6672}.hero{background:linear-gradient(135deg,#210003,#62000a,#a90014,#180004);box-shadow:0 22px 55px rgba(150,0,20,.28)}.hero-card,.hero-side,.card,.post,.modal .card,.profile,.mini,.search,.toolbar{background:linear-gradient(180deg,#141414,#0d0d0d);border-color:var(--line);color:var(--text);box-shadow:var(--shadow)}.field input,.field textarea,.field select,.search input{background:#0a0a0a;color:#f5f5f5;border-color:rgba(180,20,30,.30)}.field input::placeholder,.field textarea::placeholder,.search input::placeholder{color:#666}.tip{background:#1b0709;border-color:#6d1119;color:#ff8a92}.verified{filter:drop-shadow(0 0 5px rgba(230,20,40,.35))}.status.ok{background:rgba(34,197,94,.10)}.status.err{background:rgba(239,68,68,.10)}.otp input{background:#090909!important;color:#fff!important;border-color:#6f1019!important;box-shadow:0 0 0 1px rgba(190,15,30,.06)}.otp input:focus{border-color:#ef233c!important;box-shadow:0 0 0 3px rgba(239,35,60,.12)!important}.modal{background:rgba(0,0,0,.78)}.modal .close{background:#1a1a1a;color:#fff}.table th,.table td{border-color:#2a1718}.empty{background:#111;border-color:#3a1518;color:#aaa}.pill-pending{background:rgba(245,158,11,.10);color:#fbbf24;border-color:rgba(245,158,11,.25)}
#fm-widget-btn{background:linear-gradient(135deg,#5d000b,#d1111c)!important;box-shadow:0 8px 24px rgba(180,0,20,.38)!important}#fm-quick-wrap .fm-quick:hover{background:#a90014!important;border-color:#a90014!important}#fm-send{background:linear-gradient(135deg,#5d000b,#d1111c)!important}#fm-user{background:linear-gradient(135deg,#5d000b,#d1111c)!important}.fm-user{background:linear-gradient(135deg,#5d000b,#d1111c)!important}
@media(max-width:900px){.wrap{padding:18px 14px 36px}.brand-badge{width:58px;height:58px}.brand h1{font-size:28px}.nav{width:100%}.nav>*{flex:1;min-width:120px}.content{grid-template-columns:1fr!important}.toolbar{flex-direction:column;align-items:stretch!important}.toolbar .search{width:100%!important}.post{padding:18px!important}}
@media(max-width:600px){.wrap{padding:14px 10px 28px}.top{align-items:flex-start}.brand{width:100%}.brand-badge{width:52px;height:52px;border-radius:16px}.brand h1{font-size:23px}.brand p{font-size:12px}.nav{gap:7px}.nav>*{min-width:calc(50% - 4px);padding:10px 8px!important;font-size:12px}.hero{padding:22px 18px!important}.modal{padding:12px}.modal .card{width:100%!important;max-width:100%!important;padding:18px!important}.otp{gap:7px}.otp input{width:52px!important;height:58px!important}.feed{gap:14px}.post-head{gap:9px}.cover{max-height:280px!important}}
</style>
<!-- FM AI Widget ULTRA - مروش ال علاوي 💙 -->
<div id="fm-ai-widget" dir="rtl">
<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');
#fm-ai-widget{position:fixed;bottom:20px;right:20px;z-index:99999;font-family:'Cairo',sans-serif}
#fm-widget-btn{width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;cursor:pointer;box-shadow:0 8px 24px rgba(99,102,241,.4);display:flex;align-items:center;justify-content:center;transition:.3s ease}
#fm-widget-btn:active{transform:scale(.92)}
#fm-widget-btn.open{background:#1a1a25 !important;border:1px solid #2a2a3a}
#fm-chat-window{position:absolute;bottom:76px;right:0;width:372px;height:540px;background:#0f0f14;border-radius:20px;display:none;flex-direction:column;overflow:hidden;border:1px solid #23232f;box-shadow:0 20px 60px rgba(0,0,0,.6)}
#fm-chat-window.show{display:flex;animation:fmUp .3s ease}
@keyframes fmUp{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes fmDot{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
#fm-messages{flex:1;padding:14px;overflow-y:auto;background:#0a0a0f;display:flex;flex-direction:column;gap:10px}
#fm-messages::-webkit-scrollbar{width:3px}
#fm-messages::-webkit-scrollbar-thumb{background:#2a2a3a;border-radius:10px}
.fm-bubble{padding:10px 14px;border-radius:18px;font-size:13.5px;line-height:1.6;max-width:82%;word-break:break-word;animation:fmUp .25s ease}
.fm-user{align-self:flex-end;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-bottom-right-radius:6px}
.fm-bot{align-self:flex-start;background:#1c1c26;color:#e8e8ed;border:1px solid #23232f;border-bottom-left-radius:6px}
.fm-quick{padding:6px 12px;background:#1c1c26;border:1px solid #2a2a3a;color:#a0a0b0;border-radius:20px;font-size:12px;cursor:pointer;transition:.2s}
.fm-quick:hover{background:#6366f1;color:#fff;border-color:#6366f1}
@media(max-width:480px){#fm-chat-window{position:fixed;bottom:0;right:0;left:0;width:100%;height:82vh;border-radius:22px 22px 0 0}#fm-ai-widget{right:12px;bottom:12px}}
</style>

<button id="fm-widget-btn"><span id="fm-btn-icon" style="font-size:26px">💬</span></button>

<div id="fm-chat-window">
  <div style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;background:#12121a;border-bottom:1px solid #23232f">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:34px;height:34px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:10px;display:flex;align-items:center;justify-content:center">🤖</div>
      <div>
        <div style="color:#fff;font-weight:700;font-size:13px">كروري فالفيردي 🌟</div>
        <div id="fm-status" style="font-size:11px;color:#22c55e">● متصل الآن</div>
      </div>
    </div>
    <button onclick="fmClear()" style="background:0;border:0;color:#666;font-size:18px;cursor:pointer">🗑️</button>
  </div>

  <div id="fm-messages"></div>

  <!-- مؤشر الكتابة الجديد الحلو -->
  <div id="fm-typing" style="display:none;padding:0 14px 10px;align-items:center;gap:8px">
    <div style="width:28px;height:28px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px">🤖</div>
    <div style="background:#1c1c26;border:1px solid #23232f;padding:10px 14px;border-radius:16px;display:flex;align-items:center;gap:6px">
      <span style="font-size:11px;color:#8b8b9a;margin-left:4px">كروري يكتب</span>
      <span style="width:5px;height:5px;background:#6366f1;border-radius:50%;animation:fmDot 1s infinite"></span>
      <span style="width:5px;height:5px;background:#8b5cf6;border-radius:50%;animation:fmDot 1s .15s infinite"></span>
      <span style="width:5px;height:5px;background:#a78bfa;border-radius:50%;animation:fmDot 1s .3s infinite"></span>
    </div>
  </div>

  <div id="fm-quick-wrap" style="display:flex;gap:6px;padding:8px 14px;overflow-x:auto">
    <button class="fm-quick" onclick="fmSendQuick('شلونك؟')">شلونك؟ 👋</button>
    <button class="fm-quick" onclick="fmSendQuick('ساعدني')">ساعدني ✨</button>
    <button class="fm-quick" onclick="fmSendQuick('شنو الأخبار؟')">الأخبار 📰</button>
  </div>

  <div style="display:flex;gap:8px;padding:12px;background:#12121a;border-top:1px solid #23232f;align-items:center">
    <textarea id="fm-input" placeholder="اكتب هنا..." rows="1" style="flex:1;background:#1c1c26;border:1px solid #2a2a3a;border-radius:12px;padding:11px 14px;color:#fff;outline:none;resize:none;font-family:inherit;font-size:13px;max-height:80px"></textarea>
    <button id="fm-send" style="width:42px;height:42px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;border-radius:12px;color:#fff;cursor:pointer;font-size:16px;transition:.2s">➤</button>
  </div>
</div>
</div>

<script>
(function(){
  const API_URL='https://xkvs.pythonanywhere.com/api/bot';
  const API_KEY='FM.AI_kwW31RHj2u36aZcvh0TFkP7JDtDAhh';
  const KEY='fm_v3';
  let isOpen=false,loading=false,connected=true;
  const $=id=>document.getElementById(id);
  const btn=$('fm-widget-btn'),win=$('fm-chat-window'),msgs=$('fm-messages'),input=$('fm-input'),typing=$('fm-typing'),send=$('fm-send'),icon=$('fm-btn-icon'),status=$('fm-status');

  function init(){
    btn.onclick=toggle; send.onclick=doSend;
    input.onkeydown=e=>{ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();doSend()} };
    input.oninput=()=>{ input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,80)+'px'; send.style.transform=input.value.trim()?'scale(1.1)':'' };
    load();
  }
  function toggle(){ isOpen=!isOpen; win.classList.toggle('show',isOpen); btn.classList.toggle('open',isOpen); icon.textContent=isOpen?'✕':'💬'; if(isOpen) input.focus(); }

  function bubble(text,type){
    const d=document.createElement('div'); d.className='fm-bubble '+(type==='user'?'fm-user':'fm-bot');
    d.innerHTML=text.replace(/\n/g,'<br>');
    msgs.appendChild(d); msgs.scrollTo({top:msgs.scrollHeight,behavior:'smooth'});
    localStorage.setItem(KEY,msgs.innerHTML);
  }

  async function doSend(){
    const t=input.value.trim(); if(!t||loading) return;
    bubble(t,'user'); input.value=''; input.style.height='auto';
    loading=true; typing.style.display='flex'; msgs.scrollTop=msgs.scrollHeight;
    try{
      const r=await fetch(API_URL,{method:'POST',headers:{'Authorization':'Bearer '+API_KEY,'Content-Type':'application/json'},body:JSON.stringify({message:t})});
      const j=await r.json(); typing.style.display='none';
      if(r.ok&&j.reply) bubble(j.reply,'bot');
      else bubble('❌ صار خطأ، حاول مرة ثانية','bot');
    }catch{ typing.style.display='none'; bubble('❌ ماكو اتصال بالسيرفر','bot'); }
    loading=false;
  }

  function load(){
    const h=localStorage.getItem(KEY);
    if(h) msgs.innerHTML=h; else bubble('هلا والله! 👋\nأنا كروري فالفيردي 🌟\nشنو تريد اساعدك بيه اليوم؟','bot');
    msgs.scrollTop=msgs.scrollHeight;
  }
  window.fmClear=()=>{ if(confirm('تمسح المحادثة؟')){ localStorage.removeItem(KEY); msgs.innerHTML=''; bubble('تم المسح ✅ ابدي من جديد!','bot'); } };
  window.fmSendQuick=t=>{ input.value=t; doSend(); };

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
</script>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-6518233825484457"
     crossorigin="anonymous"></script>
</head>
<body>
<div class="wrap">
  <header class="top">
    <div class="brand">
      <div class="brand-badge"><img src="assets/united-cyber-logo.png" alt="UNITED" loading="eager"></div>
      <div>
        <h1 id="siteName"><?= htmlspecialchars($settings['site_name']) ?></h1>
        <p id="siteTagline"><?= htmlspecialchars($settings['site_tagline']) ?></p>
      </div>
    </div>
    <nav class="nav">
      <button class="ghost" onclick="loadHome(true)">الرئيسية</button>
      <button class="ghost" id="profileBtn" onclick="loadProfile(app.user?.username)" style="display:none">ملفي</button>
      <a class="ghost" id="adminBtn" href="admin_panel.php" style="display:none">لوحة التحكم</a>
      <button class="ghost" id="loginBtn" onclick="openModal('loginModal')">تسجيل الدخول</button>
      <button class="btn" id="registerBtn" onclick="openRegister()">إنشاء حساب</button>
      <button class="ghost hide" id="logoutBtn" onclick="logout()">خروج</button>
    </nav>
  </header>

  <section class="hero">
    <div class="hero-card">
      <div class="tag">ستايل جديد • تجربة أكثر حيوية</div>
      <p id="siteDescription"><?= htmlspecialchars($settings['site_description']) ?></p>
      <div class="row">
        <button class="btn" onclick="openModal('postModal')">مقال جديد</button>
        <button class="soft" onclick="document.getElementById('searchInput').focus()">ابحث عن مقال</button>
      </div>
    </div>
    <aside class="hero-side">
      <div class="stat-grid">
<div class="stat"><b id="postsCount">0</b><span>عدد المقالات</span></div>
        <div class="stat"><b id="membersCount">∞</b><span>جاهز لاستقبال المستخدمين</span></div>
      </div>
      <div class="tip">مدونه خاصه بمصممين كروري فالفيردي🌟</div>
    </aside>
  </section>

  <section class="content">
    <main>
      <div class="toolbar">
        <div class="search"><input id="searchInput" placeholder="ابحث في المقالات" oninput="renderHome()"></div>
        <button class="ghost" onclick="openModal('postModal')">مقال جديد</button>
      </div>
      <div id="view"></div>
    </main>
  </section>
</div>

<div class="modal" id="loginModal"><div class="card"><button class="close" onclick="closeModal('loginModal')">×</button><h2>تسجيل الدخول</h2><div class="field"><label>البريد الإلكتروني أو اسم المستخدم</label><input id="loginEmail"></div><div class="field"><label>كلمة المرور</label><input id="loginPassword" type="password"></div><button class="btn" onclick="login()">دخول</button><div class="status" id="loginStatus"></div><p style="margin-top:14px">نسيت كلمة المرور؟ <span class="link" onclick="closeModal('loginModal');openForgot()">استعادة</span></p></div></div>
<div class="modal" id="registerModal"><div class="card"><button class="close" onclick="closeModal('registerModal')">×</button><h2>إنشاء حساب</h2><p class="muted" style="margin:0 0 18px">لا يتم إنشاء الحساب إلا بعد تأكيد البريد الإلكتروني برمز من 4 أرقام.</p>
  <div class="step active" id="regStep1"><small>الخطوة 1 من 4</small><div class="field"><label>اسم العرض</label><input id="regDisplayName"></div><button class="btn" onclick="nextRegister(1)">التالي</button><div class="status" id="regStatus1"></div></div>
  <div class="step" id="regStep2"><small>الخطوة 2 من 4</small><div class="field"><label>اسم المستخدم</label><input id="regUsername" placeholder="username"></div><button class="btn" onclick="nextRegister(2)">التالي</button><button class="ghost" onclick="prevRegister(2)" style="margin-top:10px">السابق</button><div class="status" id="regStatus2"></div></div>
  <div class="step" id="regStep3"><small>الخطوة 3 من 4</small><div class="field"><label>البريد الإلكتروني</label><input id="regEmail" type="email"></div><div class="field"><label>كلمة المرور</label><input id="regPassword" type="password"></div><div class="field"><label>تأكيد كلمة المرور</label><input id="regPassword2" type="password"></div><button class="btn" onclick="submitRegister()">إرسال رمز التحقق</button><button class="ghost" onclick="prevRegister(3)" style="margin-top:10px">السابق</button><div class="status" id="regStatus3"></div></div>
  <div class="step" id="regStep4"><small>الخطوة 4 من 4</small><p class="muted">أدخل رمز التحقق المرسل إلى بريدك.</p><div class="otp" id="regOtpWrap"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1"></div><button class="btn" onclick="verifyRegister()">تأكيد وإنشاء الحساب</button><button class="ghost" onclick="showStep('regStep',3,4)" style="margin-top:10px">السابق</button><div class="status" id="regStatus4"></div></div>
</div></div>
<div class="modal" id="forgotModal"><div class="card"><button class="close" onclick="closeModal('forgotModal')">×</button><h2>استعادة كلمة المرور</h2><p class="muted" style="margin:0 0 18px">استرجاع سريع وآمن عبر رمز بريدي.</p><div class="step active" id="forgot1"><small>الخطوة 1 من 3</small><div class="field"><label>البريد الإلكتروني</label><input id="forgotEmail" type="email"></div><button class="btn" onclick="sendForgotOtp()">إرسال الرمز</button><div class="status" id="forgotStatus1"></div></div><div class="step" id="forgot2"><small>الخطوة 2 من 3</small><div class="otp" id="forgotOtpWrap"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1"><input inputmode="numeric" maxlength="1"></div><div class="otp-actions"><button class="btn" onclick="verifyForgotOtp()">تحقق من الرمز</button><button class="ghost" onclick="goForgot(1)">السابق</button></div><div class="tip">استخدم اللصق المباشر إذا كان الرمز منسوخاً من البريد.</div><div class="status" id="forgotStatus2"></div></div><div class="step" id="forgot3"><small>الخطوة 3 من 3</small><div class="field"><label>كلمة المرور الجديدة</label><input id="newPass" type="password"></div><div class="field"><label>تأكيد كلمة المرور</label><input id="newPass2" type="password"></div><button class="btn" onclick="finishReset()">حفظ كلمة المرور</button><div class="status" id="forgotStatus3"></div></div></div></div>
<div class="modal" id="postModal"><div class="card"><button class="close" onclick="closeModal('postModal')">×</button><h2>مقال جديد</h2><div class="field"><label>العنوان</label><input id="postTitle"></div><div class="field"><label>رابط الصورة أو ارفع صورة</label><input id="postImage"></div><div class="field"><input id="postFile" type="file" accept="image/*" onchange="uploadFile(this,'postImage','postStatus')"></div><div class="field"><label>المحتوى</label><textarea id="postContent" placeholder="اكتب محتوى المقال هنا"></textarea></div><div id="htmlSecretHint" class="tip hide">تم تفعيل وضع HTML للمقال في هذه الجلسة.</div><button class="btn" onclick="createPost()">نشر المقال</button><div class="status" id="postStatus"></div></div></div>
<div class="modal" id="profileModal"><div class="card"><button class="close" onclick="closeModal('profileModal')">×</button><h2>تعديل الملف الشخصي</h2><div class="field"><label>اسم العرض</label><input id="editDisplayName"></div><div class="field"><label>اسم المستخدم</label><input id="editUsername"></div><div class="field"><label>الصورة الشخصية</label><input id="editAvatar"></div><div class="field"><input type="file" accept="image/*" onchange="uploadFile(this,'editAvatar','profileStatus')"></div><div class="field"><label>نبذة</label><textarea id="editBio"></textarea></div><button class="btn" onclick="saveProfile()">حفظ الملف</button><hr style="border-color:var(--line);margin:18px 0"><div class="field"><label>كلمة المرور الحالية</label><input id="curPass" type="password"></div><div class="field"><label>كلمة المرور الجديدة</label><input id="nextPass" type="password"></div><button class="ghost" onclick="changePassword()">تغيير كلمة المرور</button><div class="status" id="profileStatus"></div></div></div>

<script>
const app={user:null,posts:[],settings:null,register:{},forgot:{},htmlMode:false};
const BASE_PATH=<?= json_encode($basePath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_PERMALINK=<?= json_encode($initialPermalink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const $=s=>document.querySelector(s);

async function api(data){const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/json','Cache-Control':'no-cache'},body:JSON.stringify(data)});return r.json();}
function esc(s=''){return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function avatar(u){return u||'https://ui-avatars.com/api/?background=fdedd5&color=9a3412&name='}
function badge(ok){return ok?'<span class="verified" aria-label="verified"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l2.35 2.38 3.3-.22 1.2 3.08 2.87 1.66-1.18 3.09 1.18 3.08-2.87 1.67-1.2 3.08-3.3-.22L12 21.5l-2.35-2.38-3.3.22-1.2-3.08-2.87-1.67 1.18-3.08-1.18-3.09 2.87-1.66 1.2-3.08 3.3.22L12 2.5z" fill="#0ea5e9"></path><path d="M8 12.2l2.2 2.2L16.5 8.4" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></path></svg></span>':''}
function setStatus(id,msg,type='info'){const el=document.getElementById(id);el.className='status show '+(type==='success'?'ok':type==='error'?'err':'info');el.textContent=msg;}
function clearStatus(...ids){ids.forEach(id=>{const el=document.getElementById(id);if(el){el.className='status';el.textContent='';}})}
function openModal(id){document.getElementById(id).classList.add('show')} function closeModal(id){document.getElementById(id).classList.remove('show')}
function syncAuth(){const on=!!app.user;$('#profileBtn').style.display=on?'inline-flex':'none';$('#logoutBtn').classList.toggle('hide',!on);$('#loginBtn').classList.toggle('hide',on);$('#registerBtn').classList.toggle('hide',on);$('#adminBtn').style.display=on&&app.user.role==='admin'?'inline-flex':'none';}
function setupOtp(container,callback){const boxes=[...document.querySelectorAll('#'+container+' input')];boxes.forEach((box,i)=>{box.addEventListener('input',e=>{e.target.value=e.target.value.replace(/\D/g,'').slice(0,1);if(e.target.value&&i<boxes.length-1)boxes[i+1].focus();if(boxes.every(x=>x.value.length===1)&&callback)callback();});box.addEventListener('keydown',e=>{if(e.key==='Backspace'&&!e.target.value&&i>0)boxes[i-1].focus();});box.addEventListener('paste',e=>{e.preventDefault();const nums=(e.clipboardData.getData('text')||'').replace(/\D/g,'').slice(0,boxes.length).split('');boxes.forEach((b,idx)=>b.value=nums[idx]||'');const last=Math.min(nums.length,boxes.length)-1;if(last>=0)boxes[last].focus();if(nums.length===boxes.length&&callback)callback();});});}
function otpValue(id){return [...document.querySelectorAll('#'+id+' input')].map(i=>i.value).join('')}
function showStep(prefix,n,total){for(let i=1;i<=total;i++)document.getElementById(prefix+i).classList.toggle('active',i===n)}
function articleUrl(slug){return `${BASE_PATH||''}/${slug}`.replace(/\/+/g,'/').replace(/^([^/])/, '/$1')}
function absoluteArticleUrl(slug){return location.origin+articleUrl(slug)}
function copyLink(slug){navigator.clipboard?.writeText(absoluteArticleUrl(slug));alert('تم نسخ الرابط')}
function updateStats(){document.getElementById('postsCount').textContent=String(app.posts.length)}

function openRegister(){app.register={};showStep('regStep',1,4);clearStatus('regStatus1','regStatus2','regStatus3','regStatus4');setupOtp('regOtpWrap',()=>{});openModal('registerModal')}
function nextRegister(step){if(step===1){const v=$('#regDisplayName').value.trim();if(v.length<3)return setStatus('regStatus1','اسم العرض يجب أن يكون 3 أحرف على الأقل','error');app.register.display_name=v;showStep('regStep',2,4);}if(step===2){const v=$('#regUsername').value.trim();if(!/^[a-zA-Z0-9_.]{3,20}$/.test(v))return setStatus('regStatus2','اسم المستخدم يجب أن يكون بين 3 و20 ويقبل الأحرف والأرقام و _.','error');app.register.username=v;showStep('regStep',3,4);}}
function prevRegister(step){if(step===2)showStep('regStep',1,4);if(step===3)showStep('regStep',2,4)}
async function submitRegister(){clearStatus('regStatus3');const email=$('#regEmail').value.trim().toLowerCase();const p=$('#regPassword').value;const p2=$('#regPassword2').value;if(!email.includes('@'))return setStatus('regStatus3','البريد الإلكتروني غير صالح','error');if(p.length<6)return setStatus('regStatus3','كلمة المرور قصيرة','error');if(p!==p2)return setStatus('regStatus3','كلمتا المرور غير متطابقتين','error');Object.assign(app.register,{email,password:p});setStatus('regStatus3','جاري إرسال رمز التحقق...','info');const r=await api({action:'registerStart',...app.register});if(!r.success)return setStatus('regStatus3',r.error||'فشل العملية','error');showStep('regStep',4,4);setStatus('regStatus4','تم إرسال رمز من 4 أرقام إلى بريدك','success');}
async function verifyRegister(){const otp=otpValue('regOtpWrap');if(otp.length!==4)return setStatus('regStatus4','أدخل الرمز المكون من 4 أرقام','error');setStatus('regStatus4','جاري التحقق وإنشاء الحساب...','info');const r=await api({action:'registerVerify',email:app.register.email,otp});if(!r.success)return setStatus('regStatus4',r.error||'فشل التحقق','error');app.user=r.user;syncAuth();closeModal('registerModal');await loadHome(true)}
async function login(){const r=await api({action:'login',email:$('#loginEmail').value.trim(),password:$('#loginPassword').value});if(!r.success)return setStatus('loginStatus',r.error||'فشل','error');app.user=r.user;syncAuth();closeModal('loginModal');loadHome()}
async function logout(){await api({action:'logout'});app.user=null;syncAuth();loadHome(true)}
function openForgot(){app.forgot={};showStep('forgot',1,3);clearStatus('forgotStatus1','forgotStatus2','forgotStatus3');openModal('forgotModal')}
function goForgot(n){showStep('forgot',n,3)}
async function sendForgotOtp(){app.forgot.email=$('#forgotEmail').value.trim().toLowerCase();if(!app.forgot.email.includes('@'))return setStatus('forgotStatus1','أدخل البريد الإلكتروني','error');setStatus('forgotStatus1','جاري إرسال الرمز...','info');const res=await fetch('verification.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',email:app.forgot.email,purpose:'password_reset'})});const r=await res.json();if(!r.success)return setStatus('forgotStatus1',r.error||'فشل','error');showStep('forgot',2,3);setStatus('forgotStatus2','تم إرسال الرمز','success');}
async function verifyForgotOtp(){const otp=otpValue('forgotOtpWrap');if(otp.length!==4)return setStatus('forgotStatus2','أدخل الرمز المكون من 4 أرقام كاملاً','error');const res=await fetch('verification.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'verify',email:app.forgot.email,otp,purpose:'password_reset'})});const r=await res.json();if(!r.success)return setStatus('forgotStatus2',r.error||'فشل','error');showStep('forgot',3,3)}
async function finishReset(){const a=$('#newPass').value,b=$('#newPass2').value;if(a.length<6)return setStatus('forgotStatus3','كلمة المرور قصيرة','error');if(a!==b)return setStatus('forgotStatus3','كلمتا المرور غير متطابقتين','error');const r=await api({action:'resetPassword',email:app.forgot.email,new_password:a});if(!r.success)return setStatus('forgotStatus3',r.error||'فشل','error');setStatus('forgotStatus3',r.message,'success');setTimeout(()=>{closeModal('forgotModal');openModal('loginModal')},800)}
async function uploadFile(input,target,status){const file=input.files?.[0];if(!file)return;const fd=new FormData();fd.append('image',file);setStatus(status,'جاري رفع الصورة...','info');const r=await fetch('?action=upload',{method:'POST',body:fd});const data=await r.json();if(!data.success)return setStatus(status,data.error||'فشل الرفع','error');document.getElementById(target).value=data.url;setStatus(status,'تم رفع الصورة بنجاح','success')}
async function createPost(){if(!app.user)return openModal('loginModal');const r=await api({action:'addPost',title:$('#postTitle').value.trim(),image:$('#postImage').value.trim(),content:$('#postContent').value.trim()});if(!r.success)return setStatus('postStatus',r.error||'فشل','error');closeModal('postModal');$('#postTitle').value='';$('#postImage').value='';$('#postContent').value='';if(r.permalink){await loadPostByPermalink(r.permalink,true)}else{loadHome(true)}}
function fillProfileForm(){if(!app.user)return;$('#editDisplayName').value=app.user.display_name||'';$('#editUsername').value=app.user.username||'';$('#editAvatar').value=app.user.avatar||'';$('#editBio').value=app.user.bio||'';clearStatus('profileStatus')}
async function saveProfile(){const r=await api({action:'updateProfile',display_name:$('#editDisplayName').value.trim(),username:$('#editUsername').value.trim(),avatar:$('#editAvatar').value.trim(),bio:$('#editBio').value.trim()});if(!r.success)return setStatus('profileStatus',r.error||'فشل','error');app.user=r.user;syncAuth();setStatus('profileStatus','تم حفظ الملف الشخصي','success');}
async function changePassword(){const r=await api({action:'changePassword',current_password:$('#curPass').value,new_password:$('#nextPass').value});setStatus('profileStatus',r.message||r.error,r.success?'success':'error');if(r.success){$('#curPass').value='';$('#nextPass').value='';}}
async function requestVerification(){if(!app.user)return openModal('loginModal');const r=await api({action:'createVerificationRequest'});if(!r.success)return alert(r.error||'فشل إنشاء الطلب');if(r.payment_url)window.location.href=r.payment_url}
function homeHtml(list){return list.length?`<div class="feed">${list.map(p=>`<article class="post"><div class="post-head"><img class="avatar" src="${p.author_avatar||avatar('')+encodeURIComponent(p.author_display_name||p.author_username)}"><div class="meta"><b>${esc(p.author_display_name||p.author_username)} ${badge(p.author_verified)}</b><div class="muted">@${esc(p.author_username)} • ${esc((p.date||'').slice(0,10))}</div></div>${app.user&&(app.user.id===p.author_id||app.user.role==='admin')?`<button class="ghost" onclick="removePost(${p.id})">حذف</button>`:''}</div><h2>${esc(p.title)}</h2>${p.image?`<img class="cover" src="${p.image}">`:''}<div class="excerpt">${esc(String(p.content||'').replace(/<[^>]+>/g,'').slice(0,220))}${String(p.content||'').replace(/<[^>]+>/g,'').length>220?'...':''}</div><div class="row spread" style="margin-top:16px"><div class="reactions"><button class="ghost" onclick="toggleLike(${p.id})">❤️ ${p.likes_count}</button><span class="badge">💬 ${p.comments_count}</span><span class="badge">👁️ ${p.views}</span></div><div class="row"><button class="soft" onclick="copyLink('${p.permalink}')">نسخ الرابط</button><button class="btn" onclick="loadPostByPermalink('${p.permalink}',true)">اقرأ المزيد</button></div></div></article>`).join('')}</div>`:`<div class="empty">لا توجد مقالات حالياً</div>`}
function renderHome(){const q=document.getElementById('searchInput').value.trim().toLowerCase();const list=app.posts.filter(p=>!q||p.title.toLowerCase().includes(q)||String(p.author_display_name||'').toLowerCase().includes(q)||String(p.content||'').toLowerCase().includes(q));document.getElementById('view').innerHTML=homeHtml(list)}
async function loadHome(push=false){const r=await api({action:'getPosts'});app.posts=r.posts||[];updateStats();renderHome();if(push)history.pushState({view:'home'},document.title,(BASE_PATH||'')+'/')}
function renderPost(p,push=true){document.getElementById('view').innerHTML=`<article class="article">${p.image?`<img class="cover" src="${p.image}">`:''}<div class="row spread"><div class="tag">بقلم ${esc(p.author_display_name||p.author_username)} ${badge(p.author_verified)}</div><div class="row"><span class="mini-badge">الرابط: ${esc(p.permalink)}</span><button class="soft" onclick="copyLink('${p.permalink}')">نسخ الرابط</button></div></div><h1>${esc(p.title)}</h1><div class="muted">${esc((p.date||'').slice(0,10))} • ${p.views} مشاهدة</div><div class="body">${p.content}</div><div class="row spread" style="margin-top:18px"><div class="reactions"><button class="ghost" onclick="toggleLike(${p.id});setTimeout(()=>loadPostByPermalink('${p.permalink}',false),120)">❤️ ${p.likes_count}</button><span class="badge">💬 ${p.comments_count}</span></div><button class="ghost" onclick="loadHome(true)">العودة</button></div><section class="comments"><h3>التعليقات</h3>${(p.comments||[]).length?(p.comments.map(c=>`<div class="comment"><div class="comment-head"><img class="avatar" src="${c.author_avatar||avatar('')+encodeURIComponent(c.author_display_name||c.author_username)}"><div class="meta"><b>${esc(c.author_display_name||c.author_username)} ${badge(c.author_verified)}</b><div class="muted">${esc((c.date||'').slice(0,10))}</div></div></div><div style="margin-top:10px">${esc(c.content)}</div></div>`).join('')):'<div class="comment">لا توجد تعليقات بعد</div>'}${app.user?`<div class="comment-input"><input id="commentInput" placeholder="اكتب تعليقك"><button class="btn" onclick="sendComment(${p.id}, '${p.permalink}')">نشر</button></div>`:'<div class="comment">سجّل الدخول لإضافة تعليق</div>'}</section></article>`;if(push)history.pushState({view:'post',slug:p.permalink},p.title,articleUrl(p.permalink))}
async function loadPost(id,push=true){const r=await api({action:'getPost',id});if(!r.success)return document.getElementById('view').innerHTML=`<div class="empty">${esc(r.error||'غير موجود')}</div>`;renderPost(r.post,push)}
async function loadPostByPermalink(permalink,push=true){const r=await api({action:'getPostByPermalink',permalink});if(!r.success){document.getElementById('view').innerHTML=`<div class="empty">${esc(r.error||'غير موجود')}</div>`;if(push)history.pushState({view:'home'},document.title,(BASE_PATH||'')+'/');return;}renderPost(r.post,push)}
async function sendComment(id,permalink){const content=document.getElementById('commentInput').value.trim();if(!content)return;await api({action:'addComment',post_id:id,content});loadPostByPermalink(permalink,false)}
async function toggleLike(id){const r=await api({action:'like',post_id:id});if(!r.success&&!app.user){openModal('loginModal');return;}if(r.success){const post=app.posts.find(p=>p.id===id);if(post){post.likes_count=r.count}}}
async function removePost(id){if(!confirm('حذف المقال؟'))return;const r=await api({action:'deletePost',post_id:id});if(r.success)loadHome(true)}
async function loadProfile(username){if(!username)return;const [u,posts]=await Promise.all([api({action:'getUser',username}),api({action:'getPosts'})]);if(!u.success)return;const user=u.user;const mine=app.user&&app.user.username===user.username;const myPosts=(posts.posts||[]).filter(p=>p.author_username===user.username);document.getElementById('view').innerHTML=`<section class="profile"><div class="profile-top"><img class="avatar" src="${user.avatar||avatar('')+encodeURIComponent(user.display_name)}"><div><div class="tag">@${esc(user.username)}</div><h2>${esc(user.display_name)} ${badge(user.verified)}</h2><p>${esc(user.bio||'لا توجد نبذة بعد')}</p><div class="row">${mine?'<button class="btn" onclick="fillProfileForm();openModal(\'profileModal\')">تعديل الملف</button>':''}${mine&&!user.verified&&!user.verification_requested?'<button class="ghost" onclick="requestVerification()">طلب التوثيق — 2000 د.ع / شهر</button>':''}${mine&&user.verification_requested?'<span class="pill-pending">طلب التوثيق قيد المراجعة</span>':''}</div></div></div><div class="profile-grid"><div class="mini"><b>${myPosts.length}</b><span class="muted">عدد المقالات</span></div><div class="mini"><b>${user.email_verified?'تم':'لا'}</b><span class="muted">تأكيد البريد</span></div><div class="mini"><b>${user.verified?'موثق':'عادي'}</b><span class="muted">نوع الحساب</span></div></div><div style="margin-top:24px" class="feed">${myPosts.length?myPosts.map(p=>`<article class="post"><h2>${esc(p.title)}</h2><div class="excerpt">${esc(String(p.content||'').replace(/<[^>]+>/g,'').slice(0,170))}${String(p.content||'').replace(/<[^>]+>/g,'').length>170?'...':''}</div><div class="row spread" style="margin-top:12px"><span class="badge">${esc((p.date||'').slice(0,10))}</span><div class="row"><button class="soft" onclick="copyLink('${p.permalink}')">نسخ الرابط</button><button class="btn" onclick="loadPostByPermalink('${p.permalink}',true)">عرض</button></div></div></article>`).join(''):'<div class="empty">لا توجد مقالات لهذا المستخدم</div>'}</div></section>`;history.pushState({view:'profile',user:user.username},document.title,(BASE_PATH||'')+'/')}

window.addEventListener('keydown',e=>{if(e.ctrlKey&&e.shiftKey&&e.key.toLowerCase()==='h'&&document.getElementById('postModal').classList.contains('show')){app.htmlMode=!app.htmlMode;document.getElementById('htmlSecretHint').classList.toggle('hide',!app.htmlMode)}})
window.addEventListener('popstate',()=>{const path=location.pathname.replace((BASE_PATH||''),'').replace(/^\//,'').replace(/\/$/,'');if(/^\d{4,10}$/.test(path)){loadPostByPermalink(path,false)}else{loadHome(false)}})

async function init(){setupOtp('forgotOtpWrap',()=>{});const state=await api({action:'check'});app.user=state.user;app.settings=state.settings;syncAuth();if(INITIAL_PERMALINK){await loadPostByPermalink(INITIAL_PERMALINK,false)}else{await loadHome(false)}}
window.addEventListener('load',init);
</script>
</body>
</html>
