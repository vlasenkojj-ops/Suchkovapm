<?php
// Главная страница thepm.ru: index.html + цены и фото, которые Лидия сохранила в админке (/admin/).
// Если что-то пошло не так, показываем index.html как есть: сайт не должен падать из-за админки.
header('Content-Type: text/html; charset=utf-8');
try {
    require __DIR__ . '/cms/lib.php';
    echo cms_render();
} catch (Throwable $e) {
    readfile(is_file(__DIR__ . '/cms/page.html') ? __DIR__ . '/cms/page.html' : __DIR__ . '/index.html');
}
