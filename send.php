<?php
/**
 * Обработчик формы «Перезвоните мне» для thepm.ru (хостинг reg.ru, PHP 8).
 * Имя и телефон → письмо на почту мастера и сообщение во ВКонтакте (оба сервиса ООО «ВК», РФ).
 * По SMS мастеру уходит ТОЛЬКО сигнал «Новая заявка» без имени и телефона клиента.
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
// номер посетителя Яндекс Метрики (ClientID) — есть, только если посетитель принял аналитические cookies; нужен для отметки «пришла на процедуру»
$cid = preg_replace('/\D+/', '', (string)($_POST['cid'] ?? ''));
if ($cid !== '' && strlen($cid) <= 30) $text .= "\nНомер посетителя в Метрике: $cid";

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

// 3. SMS мастеру через SMS Aero: только сигнал, БЕЗ имени и телефона клиента
//    документация: smsaero.ru/integration/documentation/api/ (GET /v2/sms/send, Basic Auth: email + API-ключ)
if (!empty($cfg['sms_email']) && !empty($cfg['sms_api_key']) && !empty($cfg['sms_phone'])) {
    $q = http_build_query(['number' => preg_replace('/\D+/', '', $cfg['sms_phone']),
        'text' => 'Новая заявка с сайта thepm.ru. Данные на почте и во ВК',
        'sign' => $cfg['sms_sign'] ?? 'SMS Aero']);
    $url = 'https://gate.smsaero.ru/v2/sms/send?' . $q;
    $auth = $cfg['sms_email'] . ':' . $cfg['sms_api_key'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERPWD => $auth]);
        curl_exec($ch); curl_close($ch);
    } else {
        @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10,
            'header' => 'Authorization: Basic ' . base64_encode($auth)]]));
    }
}

if (!$sent) out(false, 'Не получилось отправить заявку. Позвоните, пожалуйста: +7 993 769-32-33');
out(true);
