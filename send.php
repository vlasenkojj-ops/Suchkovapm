<?php
/**
 * Обработчик формы «Перезвоните мне» для thepm.ru (хостинг reg.ru, PHP 8).
 * Имя и телефон → письмо на почту мастера и сообщение во ВКонтакте (оба сервиса ООО «ВК», РФ).
 * В Telegram уходит ТОЛЬКО сигнал «Новая заявка» без имени и телефона (152-ФЗ, ч. 5 ст. 18).
 * Настройки (адреса, токены) — в config.php рядом с этим файлом. Персональные данные в файлы не пишутся.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function out($ok, $msg = '') { echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); out(false, 'Метод не поддерживается'); }

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) out(false, 'Форма временно не работает. Позвоните, пожалуйста: +7 993 769-32-33');
$cfg = require $cfgFile;

// ловушка для ботов: скрытое поле должно быть пустым
if (!empty($_POST['website'])) out(true);

$name  = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$agree = !empty($_POST['agree']);
$digits = preg_replace('/\D+/', '', $phone);

if ($name === '' || mb_strlen($name) > 60)       out(false, 'Укажите имя');
if (strlen($digits) < 10 || strlen($digits) > 15) out(false, 'Укажите телефон полностью');
if (!$agree)                                      out(false, 'Отметьте согласие на обработку данных');

// не чаще одной заявки в 30 секунд с одного адреса (храним только хеш IP и время)
$lock = sys_get_temp_dir() . '/thepm_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . 'salt-thepm');
if (is_file($lock) && time() - filemtime($lock) < 30) out(false, 'Заявка уже отправлена, подождите немного');
@touch($lock);

$name  = preg_replace('/[\r\n\t]+/', ' ', strip_tags($name));
$phone = preg_replace('/[^\d+\-() ]/', '', $phone);
$when  = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i');
$text  = "Новая заявка с сайта thepm.ru\nИмя: $name\nТелефон: $phone\nВремя (МСК): $when\nСогласие на обработку ПД: да";

function post_url($url, array $data) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $r = curl_exec($ch); curl_close($ch); return $r;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]]);
    return @file_get_contents($url, false, $ctx);
}

$sent = false;

// 1. Письмо. Отправитель — ящик НА ДОМЕНЕ (иначе mail.ru отклоняет письма, см. справку reg.ru про DMARC)
if (!empty($cfg['mail_to']) && !empty($cfg['mail_from'])) {
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта: ' . $name) . '?=';
    $headers = "From: =?UTF-8?B?" . base64_encode('Сайт thepm.ru') . "?= <{$cfg['mail_from']}>\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    if (@mail($cfg['mail_to'], $subject, $text, $headers, '-f' . $cfg['mail_from'])) $sent = true;
}

// 2. ВКонтакте: сообщение от имени сообщества мастеру (нужен ключ сообщества с правом на сообщения
//    и разрешённые сообщения от сообщества у получателя)
if (!empty($cfg['vk_token']) && !empty($cfg['vk_peer_id'])) {
    // получатель: числовой id (peer_id) или короткий адрес страницы (domain), см. dev.vk.com/ru/method/messages.send
    $to = trim((string)$cfg['vk_peer_id']);
    $who = ctype_digit($to) ? ['peer_id' => $to] : ['domain' => preg_replace('~^.*vk\.(com|ru)/~', '', $to)];
    $r = post_url('https://api.vk.com/method/messages.send', $who + [
        'random_id' => random_int(1, 2147483647), // в пределах int32
        'message' => $text, 'access_token' => $cfg['vk_token'], 'v' => $cfg['vk_api_version'] ?? '5.199']);
    $j = $r ? json_decode($r, true) : null;
    if (isset($j['response'])) $sent = true;
}

// 3. Telegram: только сигнал, БЕЗ имени и телефона
if (!empty($cfg['tg_token']) && !empty($cfg['tg_chat_id'])) {
    post_url('https://api.telegram.org/bot' . $cfg['tg_token'] . '/sendMessage', [
        'chat_id' => $cfg['tg_chat_id'],
        'text' => "🔔 Новая заявка с сайта thepm.ru ($when МСК). Имя и телефон — на почте и во ВКонтакте"]);
}

if (!$sent) out(false, 'Не получилось отправить заявку. Позвоните, пожалуйста: +7 993 769-32-33');
out(true);
