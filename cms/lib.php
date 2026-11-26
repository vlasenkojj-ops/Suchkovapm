<?php
/**
 * Мини-админка thepm.ru: общие функции.
 * Лидия меняет ТОЛЬКО цены, фото в «Мои работы» и видео в блоке «Процесс». Всё остальное на сайте ей недоступно.
 * Где что лежит на хостинге:
 *   cms/page.html       — сам сайт = index.html из GitHub (правки Лидии его НЕ меняют);
 *   data/content.php    — цены и списки фото, которые сохранила Лидия (в GitHub не попадает, выкладка его не трогает);
 *   data/backup/        — копия перед каждым сохранением (последние 50), чтобы можно было вернуть;
 *   uploads/works/      — фото, загруженные Лидией (и обложки видео);
 *   uploads/video/      — видео, загруженные Лидией (только MP4).
 * Если data/content.php нет, сайт показывает цены и фото из index.html как есть.
 */

const CMS_ROOT = __DIR__ . '/..';
// Файлы данных с расширением .php и первой строкой-заглушкой: из браузера их не прочитать,
// даже если сервер отдаёт статику мимо .htaccess (на reg.ru статику отдаёт nginx).
const CMS_DATA = CMS_ROOT . '/data/content.php';
const CMS_GUARD = "<?php http_response_code(404); exit; ?>\n";
const CMS_BACKUP = CMS_ROOT . '/data/backup';
const CMS_DENY = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
const CMS_UPLOADS = CMS_ROOT . '/uploads/works';

/** Цены, которые можно менять, в том порядке, как на сайте. Новые позиции добавляем только в коде. */
const CMS_PRICES = [
    'powder-1'    => ['Пудровое напыление', 'первичная'],
    'powder-2'    => ['Пудровое напыление', 'коррекция'],
    'hair-1'      => ['Аппаратные волоски', 'первичная'],
    'hair-2'      => ['Аппаратные волоски', 'коррекция'],
    'lips-1'      => ['Перманентный макияж губ', 'первичная'],
    'lips-2'      => ['Перманентный макияж губ', 'коррекция'],
    'eyes-1'      => ['Межресничка', 'первичная'],
    'eyes-2'      => ['Межресничка', 'коррекция'],
    'rem-1'       => ['Удаление ремувером', '1-й сеанс'],
    'rem-2'       => ['Удаление ремувером', 'повторный сеанс'],
    'light'       => ['Частичное осветление', ''],
    'cover-color' => ['Перекрытие бровей с цветокоррекцией', ''],
    'cover-rem'   => ['Перекрытие бровей с элементами удаления', ''],
];

const CMS_TABS = [
    'brows' => 'Брови',
    'lips'  => 'Губы',
    'eyes'  => 'Межресничка',
    'fix'   => 'Исправление',
    'rem'   => 'Удаление',
];

/** Шаблон страницы. На хостинге index.html лежит как cms/page.html (так «/» всегда открывает index.php),
 *  в репозитории и на GitHub Pages — обычный index.html. */
function cms_template(): string {
    $f = is_file(__DIR__ . '/page.html') ? __DIR__ . '/page.html' : CMS_ROOT . '/index.html';
    return (string)file_get_contents($f);
}

/** Цены и фото, как они стоят в index.html (из GitHub). */
function cms_defaults(?string $html = null): array {
    $html ??= cms_template();
    $prices = [];
    foreach (CMS_PRICES as $id => $_) {
        $old = ''; $now = '';
        if (preg_match('~<b data-p="' . preg_quote($id, '~') . '">(.*?)</b>~s', $html, $m)) {
            $in = $m[1];
            if (preg_match('~<s>(.*?)</s><span class="now">(.*?)</span>~s', $in, $mm)) { $old = $mm[1]; $now = $mm[2]; }
            else { $now = $in; }
        }
        $prices[$id] = ['old' => cms_digits($old), 'now' => cms_digits($now)];
    }
    $works = [];
    foreach (CMS_TABS as $tab => $_) {
        $works[$tab] = [];
        if (preg_match('~<!--works:' . $tab . '-->(.*?)<!--/works:' . $tab . '-->~s', $html, $m)) {
            preg_match_all('~<img src="([^"]+)" alt="([^"]*)"~', $m[1], $all, PREG_SET_ORDER);
            foreach ($all as $a) $works[$tab][] = ['src' => $a[1], 'alt' => html_entity_decode($a[2], ENT_QUOTES, 'UTF-8')];
        }
    }
    $videos = [];
    if (preg_match('~<!--videos-->(.*?)<!--/videos-->~s', $html, $m)) {
        preg_match_all('~<video src="([^"]+)" poster="([^"]+)"[^>]*></video></div><figcaption>(.*?)</figcaption>~s', $m[1], $all, PREG_SET_ORDER);
        foreach ($all as $a) $videos[] = ['src' => $a[1], 'poster' => $a[2], 'cap' => html_entity_decode(strip_tags($a[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }
    return ['prices' => $prices, 'works' => $works, 'videos' => $videos];
}

function cms_saved(): ?array {
    if (!is_file(CMS_DATA)) return null;
    $raw = (string)file_get_contents(CMS_DATA);
    if (str_starts_with($raw, CMS_GUARD)) $raw = substr($raw, strlen(CMS_GUARD));
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

/** То, что сейчас видят посетители: сохранённое Лидией поверх значений из index.html. */
function cms_current(): array {
    $def = cms_defaults();
    $s = cms_saved();
    if (!$s) return $def;
    foreach (CMS_PRICES as $id => $_) if (isset($s['prices'][$id]['now'])) $def['prices'][$id] = $s['prices'][$id];
    foreach (CMS_TABS as $tab => $_) if (isset($s['works'][$tab]) && is_array($s['works'][$tab])) $def['works'][$tab] = $s['works'][$tab];
    if (isset($s['videos']) && is_array($s['videos'])) $def['videos'] = $s['videos'];
    return $def;
}

function cms_digits(string $s): string { return preg_replace('/\D+/', '', $s); }

/** 6000 → «6&nbsp;000&nbsp;₽» (как на сайте: неразрывные пробелы). */
function cms_money(string $n): string {
    return number_format((int)$n, 0, '', '&nbsp;') . '&nbsp;₽';
}

function cms_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** Собрать страницу: index.html + цены и фото Лидии. */
function cms_render(): string {
    $html = cms_template();
    $s = cms_saved();
    if (!$s) return $html;
    $c = cms_current();
    foreach ($c['prices'] as $id => $p) {
        if (!isset(CMS_PRICES[$id]) || $p['now'] === '') continue;
        $inner = ($p['old'] !== '' && $p['old'] !== $p['now'])
            ? '<s>' . cms_money($p['old']) . '</s><span class="now">' . cms_money($p['now']) . '</span>'
            : cms_money($p['now']);
        $html = preg_replace_callback('~<b data-p="' . preg_quote($id, '~') . '">.*?</b>~s',
            fn() => '<b data-p="' . $id . '">' . $inner . '</b>', $html, 1);
    }
    // Микроразметка Schema.org: цены услуг и диапазон цен берём из админки, чтобы совпадали с карточками
    $nums = [];
    foreach ($c['prices'] as $id => $p) {
        if (!isset(CMS_PRICES[$id]) || $p['now'] === '') continue;
        $nums[] = (int)$p['now'];
        $html = preg_replace('~"sku":"' . preg_quote($id, '~') . '","price":"\d*"~',
            '"sku":"' . $id . '","price":"' . (int)$p['now'] . '"', $html, 1);
    }
    if ($nums) {
        $html = preg_replace('~"priceRange":"[^"]*"~', '"priceRange":"' . min($nums) . '–' . max($nums) . ' ₽"', $html, 1);
    }
    foreach ($c['works'] as $tab => $list) {
        if (!isset(CMS_TABS[$tab])) continue;
        $figs = '';
        foreach ($list as $w) {
            if (!cms_valid_src($w['src'] ?? '')) continue;
            $figs .= '<figure><div class="im"><img src="' . cms_h($w['src']) . '" alt="' . cms_h($w['alt'] ?? '') . '"' .
                (str_starts_with($w['src'], 'uploads/') ? ' loading="lazy"' : '') . '></div></figure>';
        }
        $html = preg_replace_callback('~<!--works:' . $tab . '-->.*?<!--/works:' . $tab . '-->~s',
            fn() => '<!--works:' . $tab . '-->' . $figs . '<!--/works:' . $tab . '-->', $html, 1);
    }
    if (isset($s['videos']) && is_array($s['videos'])) {
        $figs = '';
        foreach ($c['videos'] as $v) {
            if (!cms_valid_video($v['src'] ?? '') || !cms_valid_src($v['poster'] ?? '')) continue;
            $cap = (string)($v['cap'] ?? '');
            $figs .= "\n      " . '<figure class="vid"><div class="vbox"><video src="' . cms_h($v['src']) . '" poster="' . cms_h($v['poster']) .
                '" muted loop playsinline autoplay preload="' . (str_starts_with($v['src'], 'assets/') ? 'auto' : 'metadata') .
                '" aria-label="' . cms_h('Видео: ' . ($cap !== '' ? $cap : 'работа мастера')) . '"></video></div><figcaption>' . cms_typo($cap) . '</figcaption></figure>';
        }
        $html = preg_replace_callback('~<!--videos-->.*?<!--/videos-->~s', fn() => '<!--videos-->' . $figs . "\n    <!--/videos-->", $html, 1);
    }
    return $html;
}

/** Неразрывный пробел после коротких слов (в, на, до, от…), чтобы предлоги не висели в конце строки. */
function cms_typo(string $t): string {
    $t = cms_h(trim(preg_replace('/\s+/u', ' ', $t)));
    return preg_replace('/(?<=^|\s|&nbsp;)([\p{L}\d]{1,3}) /u', '$1&nbsp;', preg_replace('/(?<=^|\s|&nbsp;)([\p{L}\d]{1,3}) /u', '$1&nbsp;', $t));
}

/** Видео только из своих папок сайта, только MP4. */
function cms_valid_video(string $src): bool {
    return (bool)preg_match('~^(assets/[a-z0-9]+\.mp4|uploads/video/[a-z0-9_-]+\.mp4)$~', $src);
}

/** Фото только из своих папок сайта. */
function cms_valid_src(string $src): bool {
    return (bool)preg_match('~^(assets/[a-z0-9]+\.(jpg|png)|uploads/works/[a-z0-9_-]+\.jpg)$~', $src);
}

function cms_save(array $data): void {
    if (!is_dir(dirname(CMS_DATA))) mkdir(dirname(CMS_DATA), 0755, true);
    if (!is_dir(CMS_BACKUP)) mkdir(CMS_BACKUP, 0755, true);
    cms_protect_dir(dirname(CMS_DATA));
    if (is_file(CMS_DATA)) {
        copy(CMS_DATA, CMS_BACKUP . '/content-' . date('Y-m-d_H-i-s') . '.php');
        $old = glob(CMS_BACKUP . '/content-*.php') ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 50)) as $f) @unlink($f);
    }
    $data['saved_at'] = date('c');
    $tmp = CMS_DATA . '.tmp.php';
    file_put_contents($tmp, CMS_GUARD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, CMS_DATA);
}

/** Папку data/ никто не открывает из браузера. */
function cms_protect_dir(string $dir): void {
    $f = $dir . '/.htaccess';
    if (!is_file($f)) file_put_contents($f, CMS_DENY);
}

/** Папка для фото Лидии. Отдельный .htaccess не кладём (на reg.ru он может дать ошибку 500 для картинок):
 *  защищает то, что сохраняем только сами пересобранные JPEG со своими именами вида w-20261123-ab12cd.jpg. */
function cms_uploads_dir(): string {
    if (!is_dir(CMS_UPLOADS)) mkdir(CMS_UPLOADS, 0755, true);
    return CMS_UPLOADS;
}
