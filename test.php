<?php
declare(strict_types=1);

/**
 * Private test chat: try the bot with text, photos and voice before connecting Meta.
 * Protected by 'test_page_password' in app/config.php.
 */

require __DIR__ . '/app/bootstrap.php';

session_start();

$password = (string) cfg('test_page_password', '');
$isWeak   = $password === '' || str_contains($password, 'CHANGE-ME');

if (isset($_POST['password'])) {
    if (!$isWeak && hash_equals($password, (string) $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['bot_test'] = true;
    }
    header('Location: test.php');
    exit;
}

$authed = !$isWeak && !empty($_SESSION['bot_test']);
$action = $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$authed) {
        http_response_code(401);
        echo json_encode(['error' => 'login required']);
        exit;
    }

    $userId = 'tester';
    $conv   = Store::conversationId('test', $userId);

    if ($action === 'reset') {
        Store::touchConversation('test', $userId);
        Store::clearHistory($conv);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'site') {
        if (($_GET['refresh'] ?? '') === '1') {
            SiteKnowledge::refresh();
        } elseif (!SiteKnowledge::status()['pages']) {
            SiteKnowledge::text(); // first read
        }
        echo json_encode(SiteKnowledge::status() + ['urls' => SiteKnowledge::urls()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'send') {
        $msg = ['channel' => 'test', 'user_id' => $userId, 'event_id' => '', 'kind' => 'text', 'text' => (string) ($_POST['text'] ?? '')];

        foreach (['image' => 'image', 'voice' => 'voice'] as $field => $kind) {
            if (!empty($_FILES[$field]['tmp_name']) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
                $bytes = (string) file_get_contents($_FILES[$field]['tmp_name']);
                $mime  = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: ($_FILES[$field]['type'] ?? '');
                if ($kind === 'voice' && $mime === 'video/webm') {
                    $mime = 'audio/webm'; // browser recordings are sniffed as video
                }
                $msg['kind']  = $kind;
                $msg['media'] = ['bytes' => $bytes, 'mime' => $mime];
            }
        }

        $started = microtime(true);
        $handler = new MessageHandler();
        $handler->handle($msg);

        $last = Store::history($conv, 2);
        echo json_encode([
            'replies'  => $handler->collected,
            'heard'    => $msg['kind'] === 'voice' ? preg_replace('/^\[Voice message, transcribed\]\s*/', '', $last[0]['content'] ?? '') : null,
            'seconds'  => round(microtime(true) - $started, 1),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bot Test Chat</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --bg:#f3f4f6; --panel:#fff; --ink:#111827; --muted:#6b7280; --me:#2563eb; --line:#e5e7eb; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:'Noto Sans Bengali', system-ui, sans-serif; background:var(--bg); color:var(--ink); }
  .wrap { max-width:720px; margin:0 auto; height:100dvh; display:flex; flex-direction:column; background:var(--panel); }
  header { padding:14px 16px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; }
  header b { font-weight:500; }
  header button { border:1px solid var(--line); background:#fff; border-radius:8px; padding:6px 10px; cursor:pointer; font:inherit; }
  #log { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:10px; }
  .msg { max-width:80%; padding:10px 14px; border-radius:16px; white-space:pre-wrap; line-height:1.55; }
  .me { align-self:flex-end; background:var(--me); color:#fff; border-bottom-right-radius:4px; }
  .bot { align-self:flex-start; background:#eef0f3; border-bottom-left-radius:4px; }
  .note { align-self:center; color:var(--muted); font-size:13px; }
  .me img { max-width:180px; border-radius:10px; display:block; }
  .cards { display:flex; gap:10px; overflow-x:auto; align-self:stretch; padding-bottom:4px; }
  .card { flex:0 0 180px; border:1px solid var(--line); border-radius:12px; overflow:hidden; background:#fff; }
  .card img { width:100%; aspect-ratio:1; object-fit:cover; background:#f9fafb; display:block; }
  .card div { padding:8px 10px; font-size:14px; }
  .card a { display:block; text-align:center; padding:8px; border-top:1px solid var(--line); color:var(--me); text-decoration:none; font-size:14px; }
  form#composer { display:flex; gap:8px; padding:12px; border-top:1px solid var(--line); align-items:center; }
  #text { flex:1; padding:11px 14px; border:1px solid var(--line); border-radius:22px; font:inherit; min-width:0; }
  .icon { border:none; background:#eef0f3; width:42px; height:42px; border-radius:50%; cursor:pointer; font-size:18px; flex:0 0 42px; }
  .icon.rec { background:#dc2626; color:#fff; }
  .send { background:var(--me); color:#fff; }
  .login { max-width:340px; margin:20vh auto; padding:24px; background:#fff; border-radius:14px; display:flex; flex-direction:column; gap:12px; }
  .login input, .login button { padding:11px; font:inherit; border-radius:8px; border:1px solid var(--line); }
  .login button { background:var(--me); color:#fff; border:none; cursor:pointer; }
</style>
</head>
<body>
<?php if ($isWeak): ?>
  <div class="login"><b>টেস্ট পেজ বন্ধ আছে</b><span>app/config.php ফাইলে <code>test_page_password</code> একটা শক্ত পাসওয়ার্ড দিয়ে বদলাও, তারপর পেজটা রিফ্রেশ করো।</span></div>
<?php elseif (!$authed): ?>
  <form class="login" method="post">
    <b>বট টেস্ট চ্যাট</b>
    <input type="password" name="password" placeholder="পাসওয়ার্ড" autofocus>
    <button>লগইন</button>
  </form>
<?php else: ?>
<div class="wrap">
  <header><b>বট টেস্ট চ্যাট</b><span><button id="site" type="button">ওয়েবসাইটের তথ্য</button> <button id="reset" type="button">নতুন করে শুরু</button></span></header>
  <div id="log"><div class="note">কাস্টমারের মতো করে লিখে, ছবি দিয়ে বা ভয়েস রেকর্ড করে টেস্ট করো।</div></div>
  <form id="composer">
    <input type="file" id="image" accept="image/*" hidden>
    <button type="button" class="icon" id="pick" title="ছবি পাঠাও">🖼️</button>
    <button type="button" class="icon" id="mic" title="ভয়েস রেকর্ড">🎤</button>
    <input id="text" placeholder="মেসেজ লিখুন..." autocomplete="off">
    <button class="icon send" title="পাঠাও">➤</button>
  </form>
</div>
<script>
const log = document.getElementById('log');
const textEl = document.getElementById('text');
const imageEl = document.getElementById('image');
const mic = document.getElementById('mic');

function add(cls, content) {
  const el = document.createElement('div');
  el.className = 'msg ' + cls;
  if (content instanceof Node) el.appendChild(content); else el.textContent = content;
  log.appendChild(el);
  log.scrollTop = log.scrollHeight;
  return el;
}
function note(t) { const n = document.createElement('div'); n.className = 'note'; n.textContent = t; log.appendChild(n); log.scrollTop = log.scrollHeight; return n; }

function cards(products) {
  const row = document.createElement('div');
  row.className = 'cards';
  for (const p of products) {
    const c = document.createElement('div');
    c.className = 'card';
    const price = '৳' + Number(p.sale_price ?? p.price).toLocaleString('en-US') + (p.in_stock === false ? ' · স্টক নেই' : '');
    if (p.image) { const img = document.createElement('img'); img.src = p.image; img.alt = ''; c.appendChild(img); }
    const info = document.createElement('div'); info.textContent = p.name + '\n' + price; info.style.whiteSpace = 'pre-wrap'; c.appendChild(info);
    if (p.url) { const a = document.createElement('a'); a.href = p.url; a.target = '_blank'; a.textContent = 'বিস্তারিত দেখুন'; c.appendChild(a); }
    row.appendChild(c);
  }
  log.appendChild(row);
  log.scrollTop = log.scrollHeight;
}

async function send(form) {
  const wait = note('বট লিখছে...');
  try {
    const res = await fetch('test.php?action=send', { method: 'POST', body: form });
    const data = await res.json();
    wait.remove();
    if (data.error) { note('ত্রুটি: ' + data.error); return; }
    if (data.heard) note('বট শুনেছে: "' + data.heard + '"');
    for (const r of data.replies) r.type === 'text' ? add('bot', r.text) : cards(r.products);
    note(data.seconds + ' সেকেন্ড');
  } catch (e) { wait.remove(); note('সার্ভার থেকে উত্তর আসেনি। app/data/logs দেখো।'); }
}

document.getElementById('composer').addEventListener('submit', e => {
  e.preventDefault();
  const t = textEl.value.trim();
  if (!t) return;
  add('me', t);
  textEl.value = '';
  const f = new FormData(); f.append('text', t); send(f);
});

document.getElementById('pick').onclick = () => imageEl.click();
imageEl.onchange = () => {
  const file = imageEl.files[0]; if (!file) return;
  const img = document.createElement('img'); img.src = URL.createObjectURL(file);
  const wrap = document.createElement('div'); wrap.appendChild(img);
  const caption = textEl.value.trim();
  if (caption) { const c = document.createElement('div'); c.textContent = caption; wrap.appendChild(c); }
  add('me', wrap);
  const f = new FormData(); f.append('image', file); f.append('text', caption);
  textEl.value = ''; imageEl.value = '';
  send(f);
};

let recorder = null, chunks = [];
mic.onclick = async () => {
  if (recorder && recorder.state === 'recording') { recorder.stop(); return; }
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    recorder = new MediaRecorder(stream);
    chunks = [];
    recorder.ondataavailable = e => chunks.push(e.data);
    recorder.onstop = () => {
      stream.getTracks().forEach(t => t.stop());
      mic.classList.remove('rec'); mic.textContent = '🎤';
      const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
      const ext = (recorder.mimeType || '').includes('mp4') ? 'mp4' : 'webm';
      add('me', '🎤 ভয়েস মেসেজ');
      const f = new FormData(); f.append('voice', blob, 'voice.' + ext); send(f);
    };
    recorder.start();
    mic.classList.add('rec'); mic.textContent = '■';
  } catch (e) { note('মাইক্রোফোন চালু করা যায়নি। ব্রাউজারে মাইকের অনুমতি দাও (মাইক শুধু https সাইটে কাজ করে)।'); }
};

function showSite(data) {
  if (!data.urls.length) { note('site_pages.txt-এ কোনো লিংক নেই।'); return; }
  const when = data.fetched_at ? new Date(data.fetched_at * 1000).toLocaleString() : '-';
  let t = 'বট যা পড়েছে (' + when + '):\n';
  for (const p of data.pages) {
    t += '\n' + (p.ok ? '✅ ' : '❌ ') + p.url + '\n';
    t += p.ok ? (p.chars + ' অক্ষর: ' + p.preview.replace(/\n/g, ' ').slice(0, 200) + '...\n')
              : ('পড়া যায়নি: ' + (p.error || '') + '\n');
  }
  const el = add('bot', t);
  const btn = document.createElement('button');
  btn.textContent = 'আবার পড়ো';
  btn.style.cssText = 'margin-top:8px;padding:6px 12px;border-radius:8px;border:1px solid #ccc;background:#fff;cursor:pointer;font:inherit';
  btn.onclick = async () => { btn.disabled = true; btn.textContent = 'পড়ছে...'; const r = await fetch('test.php?action=site&refresh=1', { method: 'POST' }); showSite(await r.json()); btn.remove(); };
  el.appendChild(document.createElement('br')); el.appendChild(btn);
}
document.getElementById('site').onclick = async () => {
  const wait = note('ওয়েবসাইটের পেজ দেখছি...');
  const r = await fetch('test.php?action=site', { method: 'POST' });
  wait.remove(); showSite(await r.json());
};

document.getElementById('reset').onclick = async () => {
  await fetch('test.php?action=reset', { method: 'POST' });
  log.innerHTML = ''; note('নতুন কথোপকথন শুরু হলো।');
};
</script>
<?php endif; ?>
</body>
</html>
