<?php
/**
 * Админка thepm.ru для Лидии: только цены и фото в «Мои работы».
 * Адрес: https://thepm.ru/admin/   Пароль: в config.php (admin_password), кладётся из секрета GitHub ADMIN_PASSWORD.
 * Тексты, оформление, документы и всё остальное здесь поменять нельзя.
 */
require __DIR__ . '/../cms/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$cfgFile = __DIR__ . '/../config.php';
$cfg = is_file($cfgFile) ? (require $cfgFile) : [];
$pass = (string)($cfg['admin_password'] ?? '');
$enabled = strlen($pass) >= 10;

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('thepm_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

const IDLE = 8 * 3600; // выход через 8 часов бездействия
$logged = !empty($_SESSION['ok']) && (time() - ($_SESSION['t'] ?? 0) < IDLE);
if ($logged) $_SESSION['t'] = time(); else unset($_SESSION['ok']);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function jout(array $a, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- вход: не больше 5 ошибок за 15 минут с одного адреса ---------- */
function lock_file(): string { return sys_get_temp_dir() . '/thepm_admin_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . 'thepm-admin'); }
function locked(): bool {
    $f = lock_file();
    if (!is_file($f)) return false;
    [$n, $t] = array_map('intval', explode(':', (string)file_get_contents($f)) + [0, 0]);
    if (time() - $t > 900) { @unlink($f); return false; }
    return $n >= 5;
}
function fail_login(): void {
    $f = lock_file(); $n = 0; $t = time();
    if (is_file($f)) { [$n, $t] = array_map('intval', explode(':', (string)file_get_contents($f)) + [0, 0]); if (time() - $t > 900) { $n = 0; $t = time(); } }
    file_put_contents($f, ($n + 1) . ':' . $t);
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!$enabled) $err = 'Админка ещё не включена: нет пароля в настройках';
    elseif (locked()) $err = 'Слишком много попыток. Подождите 15 минут';
    elseif (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) $err = 'Страница устарела, обновите её';
    elseif (hash_equals($pass, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['ok'] = 1; $_SESSION['t'] = time();
        @unlink(lock_file());
        header('Location: ./'); exit;
    } else { fail_login(); usleep(700000); $err = 'Неверный пароль'; }
}

/* ---------- действия (только после входа) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['do'])) {
    if (!$logged) jout(['ok' => false, 'msg' => 'Войдите заново'], 401);
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) jout(['ok' => false, 'msg' => 'Обновите страницу'], 403);
    $do = $_GET['do'];
    $cur = cms_current();

    if ($do === 'logout') { $_SESSION = []; session_destroy(); jout(['ok' => true]); }

    if ($do === 'prices') {
        $in = json_decode((string)file_get_contents('php://input'), true)['prices'] ?? null;
        if (!is_array($in)) jout(['ok' => false, 'msg' => 'Нет данных']);
        foreach (CMS_PRICES as $id => [$name, $kind]) {
            $now = cms_digits((string)($in[$id]['now'] ?? ''));
            $old = cms_digits((string)($in[$id]['old'] ?? ''));
            $label = $name . ($kind ? ', ' . $kind : '');
            if ($now === '' || (int)$now < 1 || (int)$now > 999999) jout(['ok' => false, 'msg' => "Проверьте цену: $label"]);
            if ($old !== '' && ((int)$old > 999999)) jout(['ok' => false, 'msg' => "Проверьте старую цену: $label"]);
            $cur['prices'][$id] = ['now' => (string)(int)$now, 'old' => $old === '' ? '' : (string)(int)$old];
        }
        cms_save($cur);
        jout(['ok' => true, 'msg' => 'Цены сохранены и уже на сайте']);
    }

    if ($do === 'works') {
        $in = json_decode((string)file_get_contents('php://input'), true);
        $tab = (string)($in['tab'] ?? '');
        $list = $in['list'] ?? null;
        if (!isset(CMS_TABS[$tab]) || !is_array($list) || count($list) > 60) jout(['ok' => false, 'msg' => 'Нет данных']);
        $clean = [];
        foreach ($list as $w) {
            $src = (string)($w['src'] ?? '');
            if (!cms_valid_src($src) || !is_file(CMS_ROOT . '/' . $src)) continue;
            $alt = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($w['alt'] ?? ''))));
            $clean[] = ['src' => $src, 'alt' => mb_substr($alt, 0, 150)];
        }
        $cur['works'][$tab] = $clean;
        cms_save($cur);
        jout(['ok' => true, 'msg' => 'Сохранено, уже на сайте', 'list' => $clean]);
    }

    if ($do === 'upload') {
        $f = $_FILES['photo'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) jout(['ok' => false, 'msg' => 'Фото не загрузилось, попробуйте ещё раз']);
        if ($f['size'] > 15 * 1024 * 1024) jout(['ok' => false, 'msg' => 'Фото больше 15 МБ']);
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) jout(['ok' => false, 'msg' => 'Это не фото (нужен JPG, PNG или WEBP)']);
        $name = 'w-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.jpg';
        $dest = cms_uploads_dir() . '/' . $name;
        if (function_exists('imagecreatefromstring')) {
            // пересобираем картинку: убирает данные камеры (EXIF с геометкой) и всё лишнее внутри файла
            $im = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
            if (!$im) jout(['ok' => false, 'msg' => 'Не получилось открыть фото']);
            $w = imagesx($im); $h = imagesy($im); $max = 1600;
            if (max($w, $h) > $max) { $k = $max / max($w, $h); $im = imagescale($im, (int)round($w * $k), (int)round($h * $k), IMG_BICUBIC); }
            $bg = imagecreatetruecolor(imagesx($im), imagesy($im));
            imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
            imagecopy($bg, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
            imageinterlace($bg, true);
            imagejpeg($bg, $dest, 84);
        } else {
            if ($info[2] !== IMAGETYPE_JPEG) jout(['ok' => false, 'msg' => 'На сервере можно загрузить только JPG']);
            move_uploaded_file($f['tmp_name'], $dest);
        }
        jout(['ok' => true, 'src' => 'uploads/works/' . $name]);
    }

    if ($do === 'undo') {
        $b = glob(CMS_BACKUP . '/content-*.php') ?: [];
        if (!$b) jout(['ok' => false, 'msg' => 'Отменять нечего']);
        sort($b);
        rename(end($b), CMS_DATA);
        jout(['ok' => true, 'msg' => 'Вернула, как было до последнего сохранения']);
    }
    jout(['ok' => false, 'msg' => 'Неизвестное действие'], 400);
}

$data = $logged ? cms_current() : null;
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Правка сайта · thepm.ru</title>
<link rel="icon" href="../favicon.svg" type="image/svg+xml">
<style>
@font-face{font-family:Jost;src:url(../fonts/jost.ttf) format("truetype");font-weight:300 600;font-display:swap}
@font-face{font-family:Playfair;src:url(../fonts/playfair.ttf) format("truetype");font-weight:400 700;font-display:swap}
:root{--ink:#2b2224;--marsala:#7a2a3a;--powder:#f3e9e6;--line:#e4d8d4;--stone:#8a7d7a;--ok:#2f6b45;--bad:#a12b2b;--bg:#faf7f5}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:300 16px/1.5 Jost,system-ui,sans-serif}
h1,h2{font-family:Playfair,Georgia,serif;font-weight:400;margin:0}
h1{font-size:28px}
h2{font-size:22px;margin-bottom:6px}
.wrap{max-width:820px;margin:0 auto;padding:20px 16px 120px}
header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
header a,.link{color:var(--marsala)}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px 16px;margin:16px 0}
.hint{color:var(--stone);font-size:14px;margin:0 0 14px}
.btn{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 22px;border-radius:999px;background:var(--ink);color:#fff;font-size:13px;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;text-align:center}
.btn.light{background:#fff;color:var(--ink);box-shadow:inset 0 0 0 1px var(--line)}
.btn.wine{background:var(--marsala)}
.btn:disabled{opacity:.5}
.btn:focus-visible,input:focus-visible,.tabs button:focus-visible{outline:2px solid var(--marsala);outline-offset:2px}
.prow{display:grid;grid-template-columns:1fr 110px 110px;gap:10px;align-items:end;padding:12px 0;border-top:1px solid var(--line)}
.prow:first-of-type{border-top:0}
.prow .nm b{font-weight:500;display:block}
.prow .nm span{color:var(--stone);font-size:14px}
label.f{display:block;font-size:12px;color:var(--stone)}
input[type=text],input[type=password],input[inputmode]{width:100%;height:46px;border:1px solid var(--line);border-radius:12px;padding:0 12px;font:400 17px Jost,system-ui,sans-serif;color:var(--ink);background:#fff}
.old input{color:var(--stone);text-decoration:line-through}
.old input::placeholder{text-decoration:none;color:#c9bcb8}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 16px}
.tabs button{all:unset;cursor:pointer;height:40px;padding:0 16px;border-radius:999px;box-shadow:inset 0 0 0 1px var(--line);font-size:12px;letter-spacing:.14em;text-transform:uppercase}
.tabs button[aria-selected=true]{background:var(--ink);color:#fff;box-shadow:none}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
.ph{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
.ph img{width:100%;height:170px;object-fit:contain;background:var(--powder);display:block}
.ph .n{font-size:12px;color:var(--stone);padding:6px 10px 0}
.ph .ctl{display:flex;justify-content:space-between;padding:6px}
.ph .ctl button{all:unset;cursor:pointer;width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
.ph .ctl button:hover{background:var(--powder)}
.ph .ctl .del{color:var(--bad)}
.ph.new{outline:2px solid var(--marsala)}
.add{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.toast{position:fixed;left:50%;bottom:20px;transform:translateX(-50%);max-width:calc(100% - 32px);background:var(--ink);color:#fff;padding:14px 20px;border-radius:14px;font-size:15px;opacity:0;pointer-events:none;transition:opacity .3s;z-index:9}
.toast.on{opacity:1}
.toast.bad{background:var(--bad)}
.login{max-width:380px;margin:12vh auto 0}
.err{color:var(--bad);margin:10px 0 0}
.bar{position:sticky;bottom:0;background:linear-gradient(transparent,var(--bg) 30%);padding:18px 0 4px;display:flex;gap:10px;flex-wrap:wrap}
@media (max-width:560px){.prow{grid-template-columns:1fr 1fr}.prow .nm{grid-column:1/-1}.grid{grid-template-columns:repeat(2,1fr)}.ph img{height:150px}}
</style>
</head>
<body>
<div class="wrap">
<?php if (!$logged): ?>
  <form class="card login" method="post" action="./">
    <h1>Правка сайта</h1>
    <p class="hint">Здесь можно поменять цены и фото работ на&nbsp;thepm.ru</p>
    <?php if (!$enabled): ?><p class="err">Админка ещё не включена. Напишите Юле</p><?php endif; ?>
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="csrf" value="<?= cms_h($_SESSION['csrf']) ?>">
    <label class="f" for="pw">Пароль</label>
    <input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>
    <?php if ($err): ?><p class="err" role="alert"><?= cms_h($err) ?></p><?php endif; ?>
    <p style="margin:16px 0 0"><button class="btn" type="submit" style="width:100%">Войти</button></p>
  </form>
<?php else: ?>
  <header>
    <h1>Правка сайта</h1>
    <div style="display:flex;gap:16px;align-items:center"><a href="/" target="_blank" rel="noopener">Открыть сайт ↗</a><a href="#" id="out">Выйти</a></div>
  </header>

  <section class="card" id="prices">
    <h2>Цены</h2>
    <p class="hint">Пишите только цифры, например 6000. «Старая цена» показывается зачёркнутой рядом с&nbsp;новой. Если старой цены нет, оставьте поле пустым</p>
    <form id="pf">
      <?php foreach (CMS_PRICES as $id => [$name, $kind]): $p = $data['prices'][$id]; ?>
      <div class="prow">
        <div class="nm"><b><?= cms_h($name) ?></b><?php if ($kind): ?><span><?= cms_h($kind) ?></span><?php endif; ?></div>
        <div class="old"><label class="f" for="o-<?= $id ?>">Старая цена</label><input id="o-<?= $id ?>" data-id="<?= $id ?>" data-k="old" inputmode="numeric" pattern="[0-9 ]*" value="<?= cms_h($p['old']) ?>" placeholder="нет"></div>
        <div><label class="f" for="n-<?= $id ?>">Цена, ₽</label><input id="n-<?= $id ?>" data-id="<?= $id ?>" data-k="now" inputmode="numeric" pattern="[0-9 ]*" value="<?= cms_h($p['now']) ?>" required></div>
      </div>
      <?php endforeach; ?>
      <div class="bar"><button class="btn wine" type="submit">Сохранить цены</button></div>
    </form>
  </section>

  <section class="card" id="works">
    <h2>Фото в «Мои работы»</h2>
    <p class="hint">Выберите раздел. Новое фото встаёт первым. Стрелками меняется порядок, крестиком фото убирается с&nbsp;сайта. Всё сохраняется сразу</p>
    <div class="tabs" role="tablist"><?php $first = true; foreach (CMS_TABS as $t => $n): ?><button type="button" role="tab" data-tab="<?= $t ?>" aria-selected="<?= $first ? 'true' : 'false' ?>"><?= cms_h($n) ?></button><?php $first = false; endforeach; ?></div>
    <div class="add">
      <label class="btn wine" for="file">+ Добавить фото</label>
      <input id="file" type="file" accept="image/*" multiple hidden>
      <span class="hint" id="upst" style="margin:0"></span>
    </div>
    <div class="grid" id="grid"></div>
  </section>

  <section class="card">
    <h2>Если что-то пошло не так</h2>
    <p class="hint">Кнопка возвращает цены и фото к&nbsp;состоянию до&nbsp;последнего сохранения. Можно нажимать несколько раз</p>
    <button class="btn light" id="undo" type="button">Отменить последнее сохранение</button>
  </section>
<?php endif; ?>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<?php if ($logged): ?>
<script>
(function(){
var CSRF=<?= json_encode($_SESSION['csrf']) ?>;
var WORKS=<?= json_encode($data['works'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
var TABS=<?= json_encode(CMS_TABS, JSON_UNESCAPED_UNICODE) ?>;
var tab='brows', grid=document.getElementById('grid'), t;
function toast(m,bad){var el=document.getElementById('toast');el.textContent=m;el.className='toast on'+(bad?' bad':'');clearTimeout(t);t=setTimeout(function(){el.className='toast'+(bad?' bad':'')},bad?6000:3000)}
function api(act,body,isForm){return fetch('?do='+act,{method:'POST',credentials:'same-origin',headers:isForm?{'X-CSRF':CSRF}:{'X-CSRF':CSRF,'Content-Type':'application/json'},body:isForm?body:JSON.stringify(body||{})}).then(function(r){if(r.status===401){location.reload();throw 0}return r.json()}).catch(function(e){if(e!==0)toast('Нет связи с сайтом. Проверьте интернет и попробуйте ещё раз',1);throw e})}

/* цены */
document.getElementById('pf').addEventListener('submit',function(e){e.preventDefault();var p={};
  this.querySelectorAll('input[data-id]').forEach(function(i){p[i.dataset.id]=p[i.dataset.id]||{};p[i.dataset.id][i.dataset.k]=i.value.replace(/\D/g,'')});
  var b=this.querySelector('button');b.disabled=true;
  api('prices',{prices:p}).then(function(r){toast(r.msg,!r.ok)}).finally(function(){b.disabled=false})});

/* фото */
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function draw(fresh){var l=WORKS[tab]||[];
  grid.innerHTML=l.length?l.map(function(w,i){return '<div class="ph'+(fresh&&i<fresh?' new':'')+'"><img src="../'+esc(w.src)+'" alt="'+esc(w.alt)+'" loading="lazy"><div class="n">'+(i+1)+' из '+l.length+'</div><div class="ctl"><button type="button" data-a="up" data-i="'+i+'" aria-label="Левее">←</button><button type="button" data-a="down" data-i="'+i+'" aria-label="Правее">→</button><button type="button" class="del" data-a="del" data-i="'+i+'" aria-label="Убрать с сайта">✕</button></div></div>'}).join(''):'<p class="hint">В этом разделе пока нет фото</p>'}
function save(msg){return api('works',{tab:tab,list:WORKS[tab]}).then(function(r){if(r.ok){WORKS[tab]=r.list;toast(msg||r.msg)}else toast(r.msg,1)})}
document.querySelector('.tabs').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;
  this.querySelectorAll('button').forEach(function(x){x.setAttribute('aria-selected',x===b)});tab=b.dataset.tab;draw()});
grid.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;var i=+b.dataset.i,l=WORKS[tab];
  if(b.dataset.a==='del'){if(!confirm('Убрать это фото с сайта? Вернуть можно кнопкой «Отменить последнее сохранение»'))return;l.splice(i,1);draw();save('Фото убрано с сайта');return}
  var j=b.dataset.a==='up'?i-1:i+1;if(j<0||j>=l.length)return;var x=l[i];l[i]=l[j];l[j]=x;draw();save('Порядок сохранён')});

/* уменьшаем фото в браузере до 1600 px: быстрее грузится даже по мобильному интернету */
function shrink(file){return new Promise(function(ok,no){var img=new Image(),u=URL.createObjectURL(file);
  img.onload=function(){var k=Math.min(1,1600/Math.max(img.naturalWidth,img.naturalHeight)),c=document.createElement('canvas');
    c.width=Math.round(img.naturalWidth*k);c.height=Math.round(img.naturalHeight*k);var x=c.getContext('2d');x.fillStyle='#fff';x.fillRect(0,0,c.width,c.height);x.drawImage(img,0,0,c.width,c.height);
    URL.revokeObjectURL(u);c.toBlob(function(b){b?ok(b):no()},'image/jpeg',.88)};
  img.onerror=function(){URL.revokeObjectURL(u);no()};img.src=u})}
document.getElementById('file').addEventListener('change',function(){var files=[].slice.call(this.files),st=document.getElementById('upst'),added=[],n=0;this.value='';if(!files.length)return;
  (function next(){if(!files.length){if(added.length){WORKS[tab]=added.concat(WORKS[tab]||[]);draw(added.length);save(added.length===1?'Фото добавлено на сайт':'Добавлено фото: '+added.length)}st.textContent='';return}
    var f=files.shift();n++;st.textContent='Загружаю '+n+'…';
    shrink(f).catch(function(){return f}).then(function(b){var fd=new FormData();fd.append('photo',b,'photo.jpg');return api('upload',fd,true)})
      .then(function(r){if(r.ok)added.push({src:r.src,alt:TABS[tab]+' — работа Лидии Сучковой, Ярославль'});else toast(r.msg,1)})
      .catch(function(){}).then(next)})()});

document.getElementById('undo').addEventListener('click',function(){if(!confirm('Вернуть цены и фото, как было до последнего сохранения?'))return;
  api('undo').then(function(r){toast(r.msg,!r.ok);if(r.ok)setTimeout(function(){location.reload()},900)})});
document.getElementById('out').addEventListener('click',function(e){e.preventDefault();api('logout').then(function(){location.reload()})});
draw();
})();
</script>
<?php endif; ?>
</body>
</html>
