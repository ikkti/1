<?php
require_once __DIR__ . '/app_core.php';
app_bootstrap();
if (empty($_SESSION['logged_in'])) { header('Location: index.php'); exit; }
$currentUser = app_find_user_by_id((int)$_SESSION['user_id']);
if (!$currentUser || ($currentUser['role'] ?? 'user') !== 'admin') { header('Location: index.php'); exit; }

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_settings'])) {
        $settings = app_get_settings();
        $settings['site_name'] = trim((string)($_POST['site_name'] ?? $settings['site_name']));
        $settings['site_tagline'] = trim((string)($_POST['site_tagline'] ?? $settings['site_tagline']));
        $settings['site_description'] = trim((string)($_POST['site_description'] ?? $settings['site_description']));
        $settings['verification_email_subject'] = trim((string)($_POST['verification_email_subject'] ?? $settings['verification_email_subject']));
        $settings['verification_email_template'] = (string)($_POST['verification_email_template'] ?? $settings['verification_email_template']);
        app_save_settings($settings);
        $message = 'تم حفظ إعدادات الموقع بنجاح';
    }

    if (isset($_POST['delete_user'])) {
        $userId = (int)$_POST['user_id'];
        if ($userId !== (int)$currentUser['id']) {
            $data = app_get_users_data();
            $data['users'] = array_values(array_filter($data['users'], fn($u) => (int)$u['id'] !== $userId));
            app_save_users_data($data);
            $message = 'تم حذف المستخدم';
        }
    }

    if (isset($_POST['toggle_verified'])) {
        $userId = (int)$_POST['user_id'];
        $user = app_find_user_by_id($userId);
        if ($user) {
            if (!empty($user['verified'])) {
                app_update_user($userId, [
                    'verified' => false,
                    'verification_requested' => false,
                    'verification_method' => '',
                    'verified_at' => '',
                ]);
                $message = 'تم إلغاء توثيق الحساب';
            } else {
                app_mark_user_as_verified($userId, ['verification_method' => 'admin']);
                $message = 'تم توثيق الحساب يدوياً';
            }
        }
    }

    if (isset($_POST['toggle_role'])) {
        $userId = (int)$_POST['user_id'];
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        app_update_user($userId, ['role' => $role]);
        $message = 'تم تحديث الدور';
    }

    if (isset($_POST['approve_request'])) {
        $requestId = (string)($_POST['request_id'] ?? '');
        if ($requestId !== '') {
            app_approve_verification_request($requestId, (int)$currentUser['id']);
            $message = 'تمت الموافقة على طلب التوثيق';
        }
    }

    if (isset($_POST['reject_request'])) {
        $requestId = (string)($_POST['request_id'] ?? '');
        if ($requestId !== '') {
            app_reject_verification_request($requestId, (int)$currentUser['id']);
            $message = 'تم رفض طلب التوثيق';
        }
    }
}

$settings = app_get_settings();
$users = app_get_users_data()['users'] ?? [];
$requests = array_reverse(app_get_verification_requests()['requests'] ?? []);
$pendingCount = count(array_filter($requests, fn($r) => in_array(($r['status'] ?? ''), ['pending', 'payment_pending'], true)));
$verifiedCount = count(array_filter($users, fn($u) => !empty($u['verified'])));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>لوحة التحكم</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#080808;--card:#111111;--line:rgba(180,20,30,.28);--text:#f5f5f5;--muted:#999;--pri:#8b0000;--sec:#d1111c;--ok:#22c55e;--danger:#ef4444;--warn:#f59e0b;--sky:#b91c1c}*{box-sizing:border-box}body{margin:0;font-family:'Cairo',sans-serif;background:radial-gradient(circle at top right,#350507 0,#14090a 30%,#080808 70%,#050505 100%);color:var(--text)}.wrap{max-width:1320px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:22px}.btn,.ghost{border:none;border-radius:16px;padding:12px 16px;cursor:pointer;font-family:inherit;font-weight:700}.btn{background:linear-gradient(135deg,var(--pri),var(--sec));color:#fff;box-shadow:0 10px 28px rgba(180,0,20,.18)}.ghost{background:#151515;border:1px solid var(--line);color:var(--text)}.grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px}.card{background:linear-gradient(180deg,#151515,#0d0d0d);border:1px solid var(--line);border-radius:26px;padding:22px;box-shadow:0 18px 48px rgba(15,23,42,.06)}.card h2{margin:0 0 14px}.muted{color:var(--muted)}.field{margin-bottom:14px}.field label{display:block;margin-bottom:8px}.field input,.field textarea,.field select{width:100%;padding:14px 16px;border-radius:16px;border:1px solid var(--line);background:#0b0b0b;color:#f5f5f5;font-family:inherit}.field textarea{min-height:140px;resize:vertical}.msg{padding:12px 14px;border-radius:14px;background:rgba(16,185,129,.10);border:1px solid rgba(16,185,129,.20);margin-bottom:16px}.table-wrap{width:100%;overflow:auto;border-radius:18px}.table{width:100%;border-collapse:collapse;font-size:14px;min-width:760px}.table th,.table td{padding:12px;border-bottom:1px solid #f3e3d6;text-align:right;vertical-align:top}.pill{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.ok{background:rgba(16,185,129,.12);color:#047857}.warn{background:rgba(245,158,11,.12);color:#b45309}.role{background:rgba(139,92,246,.12);color:#6d28d9}.danger{background:rgba(239,68,68,.12);color:#b91c1c}.stack{display:flex;gap:10px;flex-wrap:wrap}.hint{background:#1b0809;border:1px dashed #7f1d1d;padding:14px;border-radius:18px;color:#ff737b}.small{font-size:12px}.hero{padding:18px 20px;border-radius:24px;background:linear-gradient(135deg,#350000,#a90012,#e11d2e);color:#fff;box-shadow:0 18px 40px rgba(170,0,18,.22)}@media(max-width:980px){.grid{grid-template-columns:1fr}}@media(max-width:640px){.wrap{padding:16px}.card{padding:18px}.table{font-size:13px}.table th,.table td{padding:10px}}
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
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="hero" style="flex:1;min-width:280px">
      <h1 style="margin:0 0 8px">لوحة التحكم</h1>
      <div>إدارة الموقع والمستخدمين وطلبات التوثيق المجاني</div>
    </div>
    <div class="stack"><a class="ghost" href="index.php">العودة للموقع</a></div>
  </div>

  <?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>

  <div class="grid">
    <section class="card">
      <h2>إعدادات الموقع</h2>
      <form method="post">
        <div class="field"><label>اسم الموقع</label><input name="site_name" value="<?= htmlspecialchars($settings['site_name']) ?>"></div>
        <div class="field"><label>الشعار النصي</label><input name="site_tagline" value="<?= htmlspecialchars($settings['site_tagline']) ?>"></div>
        <div class="field"><label>وصف الموقع</label><textarea name="site_description"><?= htmlspecialchars($settings['site_description']) ?></textarea></div>
        <hr style="border-color:var(--line);margin:22px 0">
        <h3 style="margin:0 0 12px">قالب بريد التحقق</h3>
        <div class="hint" style="margin-bottom:14px">المتغيرات المتاحة: {{site_name}} — {{otp_code}} — {{email}} — {{purpose_label}} — {{validity_minutes}}</div>
        <div class="field"><label>عنوان البريد</label><input name="verification_email_subject" value="<?= htmlspecialchars($settings['verification_email_subject']) ?>"></div>
        <div class="field"><label>قالب HTML</label><textarea name="verification_email_template" style="min-height:300px;direction:ltr;text-align:left;font-family:monospace"><?= htmlspecialchars($settings['verification_email_template']) ?></textarea></div>
        <button class="btn" type="submit" name="save_settings">حفظ الإعدادات والقالب</button>
      </form>
    </section>

    <section class="card">
      <h2>ملخص سريع</h2>
      <div class="stack">
        <div class="hint" style="flex:1"><b><?= count($users) ?></b><br>إجمالي المستخدمين</div>
        <div class="hint" style="flex:1"><b><?= $verifiedCount ?></b><br>حسابات موثقة</div>
        <div class="hint" style="flex:1"><b><?= $pendingCount ?></b><br>طلبات قيد المراجعة</div>
      </div>
      <div class="hint" style="margin-top:14px">التوثيق المدفوع: <b>2000 د.ع شهرياً</b> عبر ZainCash. الطلبات الناجحة تُفعّل تلقائياً لمدة شهر.</div>
    </section>
  </div>

  <section class="card" style="margin-top:18px">
    <h2>طلبات التوثيق والدفع</h2>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>المستخدم</th><th>البريد</th><th>الحالة</th><th>المبلغ</th><th>تاريخ الطلب</th><th>الإجراءات</th></tr></thead>
      <tbody>
      <?php if (!$requests): ?>
        <tr><td colspan="6" class="muted">لا توجد طلبات حالياً</td></tr>
      <?php endif; ?>
      <?php foreach ($requests as $request): ?>
        <tr>
          <td><b><?= htmlspecialchars($request['display_name'] ?? '') ?></b><br><span class="muted">@<?= htmlspecialchars($request['username'] ?? '') ?></span></td>
          <td><?= htmlspecialchars($request['email'] ?? '') ?></td>
          <td>
            <?php $status = (string)($request['status'] ?? 'pending'); ?>
            <span class="pill <?= $status === 'approved' ? 'ok' : ($status === 'rejected' ? 'danger' : 'warn') ?>">
              <?= $status === 'approved' ? 'مدفوع ومفعل' : ($status === 'rejected' ? 'مرفوض' : ($status === 'payment_pending' ? 'بانتظار الدفع' : 'قيد المراجعة')) ?>
            </span>
          </td>
          <td><?= !empty($request['amount']) ? htmlspecialchars((string)$request['amount']) . ' د.ع / شهر' : '—' ?></td>
          <td><?= htmlspecialchars((string)($request['created_at'] ?? '-')) ?></td>
          <td>
            <?php if ($status === 'pending'): ?>
              <div class="stack">
                <form method="post"><input type="hidden" name="request_id" value="<?= htmlspecialchars((string)$request['request_id']) ?>"><button class="btn" name="approve_request">موافقة</button></form>
                <form method="post"><input type="hidden" name="request_id" value="<?= htmlspecialchars((string)$request['request_id']) ?>"><button class="ghost" style="border-color:rgba(239,68,68,.22);color:#b91c1c" name="reject_request">رفض</button></form>
              </div>
            <?php else: ?>
              <span class="muted small">تمت المعالجة</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <section class="card" style="margin-top:18px">
    <h2>إدارة المستخدمين</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>المستخدم</th><th>البريد</th><th>التوثيق</th><th>الدور</th><th>الإجراءات</th></tr></thead><tbody>
      <?php foreach ($users as $user): ?>
      <tr>
        <td><b><?= htmlspecialchars($user['display_name']) ?></b><br><span class="muted">@<?= htmlspecialchars($user['username']) ?></span></td>
        <td><?= htmlspecialchars($user['email']) ?><br><span class="pill <?= !empty($user['email_verified']) ? 'ok' : 'warn' ?>"><?= !empty($user['email_verified']) ? 'تم تأكيده' : 'غير مؤكد' ?></span></td>
        <td>
          <span class="pill <?= !empty($user['verified']) ? 'ok' : 'warn' ?>"><?= !empty($user['verified']) ? 'موثق' : (!empty($user['verification_requested']) ? 'طلب معلّق' : 'غير موثق') ?></span>
          <div class="muted small" style="margin-top:6px"><?= htmlspecialchars((string)($user['verification_method'] ?? '')) ?></div>
        </td>
        <td>
          <form method="post" class="stack"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><select name="role"><option value="user" <?= ($user['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>user</option><option value="admin" <?= ($user['role'] ?? 'user') === 'admin' ? 'selected' : '' ?>>admin</option></select><button class="ghost" name="toggle_role">حفظ</button></form>
        </td>
        <td>
          <div class="stack">
            <form method="post"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button class="ghost" name="toggle_verified"><?= !empty($user['verified']) ? 'إلغاء التوثيق' : 'توثيق يدوي' ?></button></form>
            <?php if ((int)$user['id'] !== (int)$currentUser['id']): ?><form method="post" onsubmit="return confirm('حذف المستخدم؟')"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><button class="ghost" style="border-color:rgba(239,68,68,.22);color:#b91c1c" name="delete_user">حذف</button></form><?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
</div>
</body>
</html>
