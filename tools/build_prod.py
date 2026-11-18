#!/usr/bin/env python3
"""Сборка боевой версии сайта для thepm.ru из репозитория vlasenkojj-ops/suchkovapm.
Запуск: python3 build_prod.py <папка-репозитория> <выходной.zip>
Что делает: убирает noindex, открывает robots.txt, добавляет sitemap.xml, canonical,
.htaccess (www → без www, http → https), config.php-шаблон. README/.nojekyll/.git не берёт."""
import sys, os, re, zipfile, datetime
src, out = sys.argv[1], sys.argv[2]
D = 'https://thepm.ru/'
skip = {'.git', '.github', 'tools', 'README.md', '.nojekyll', '.gitignore', 'config.sample.php'}
today = datetime.date.today().isoformat()
files = {}
for root, dirs, fs in os.walk(src):
    dirs[:] = [d for d in dirs if d not in skip]
    for f in fs:
        rel = os.path.relpath(os.path.join(root, f), src)
        if rel in skip: continue
        files[rel] = open(os.path.join(root, f), 'rb').read()
for page in ['index.html', 'policy.html', 'consent.html', 'cookies.html']:
    s = files[page].decode('utf-8')
    s, n = re.subn(r'\s*<meta name="robots" content="noindex, nofollow">', '', s)
    assert n == 1, page
    if page == 'index.html':
        s, n = re.subn(r'\s*<div class="ribbon">[^<]*</div>', '', s)
        assert n == 1, 'плашка превью не найдена'
    url = D if page == 'index.html' else D + page
    if 'rel="canonical"' not in s:
        s = s.replace('</title>', '</title>\n<link rel="canonical" href="%s">' % url, 1)
    files[page] = s.encode('utf-8')
files['robots.txt'] = ('User-agent: *\nAllow: /\nDisallow: /send.php\nDisallow: /config.php\n\nSitemap: %ssitemap.xml\n' % D).encode()
urls = [(D, '1.0'), (D + 'policy.html', '0.3'), (D + 'consent.html', '0.3'), (D + 'cookies.html', '0.3')]
files['sitemap.xml'] = ('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
    + ''.join('  <url><loc>%s</loc><lastmod>%s</lastmod><priority>%s</priority></url>\n' % (u, today, p) for u, p in urls)
    + '</urlset>\n').encode()
# правила редиректов по справке reg.ru (help.reg.ru/support/hosting/redirekty/...)
files['.htaccess'] = b'''AddDefaultCharset UTF-8
RewriteEngine On
# www.thepm.ru -> thepm.ru
RewriteCond %{HTTP_HOST} ^www\\.thepm\\.ru$ [NC]
RewriteRule ^(.*)$ https://thepm.ru/$1 [R=301,L]
# http -> https
RewriteCond %{HTTPS} =off
RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
'''
files['config.php'] = open(os.path.join(src, 'config.sample.php'), 'rb').read().replace(
    "// На хостинге этот файл называется config.php и лежит рядом с send.php.".encode(),
    "// Файл лежит рядом с send.php.".encode())
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for rel in sorted(files):
        z.writestr(rel, files[rel])
print(len(files), 'файлов →', out)
