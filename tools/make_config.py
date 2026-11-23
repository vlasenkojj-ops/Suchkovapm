#!/usr/bin/env python3
"""Собирает config.php для хостинга из config.sample.php и переменных окружения
(секреты GitHub Actions). Пустой секрет = поле остаётся пустым, канал выключен.
Запуск: python3 tools/make_config.py config.sample.php dist/config.php"""
import os, re, sys
src, out = sys.argv[1], sys.argv[2]
s = open(src, encoding='utf-8').read()
keys = {'vk_token': 'VK_TOKEN', 'vk_peer_id': 'VK_PEER_ID',
        'sms_email': 'SMS_EMAIL', 'sms_api_key': 'SMS_API_KEY',
        'sms_phone': 'SMS_PHONE', 'sms_sign': 'SMS_SIGN',
        'admin_password': 'ADMIN_PASSWORD'}
for key, env in keys.items():
    val = os.environ.get(env, '').strip()
    if not val:
        continue
    val = val.replace('\\', '\\\\').replace("'", "\\'")
    s, n = re.subn(r"('%s'\s*=>\s*)'[^']*'" % key, lambda m: m.group(1) + "'" + val + "'", s)
    assert n == 1, key
s = s.replace('На хостинге этот файл называется config.php и лежит рядом с send.php.', 'Файл собран автоматически при выкладке из GitHub, править на хостинге бесполезно: изменения сотрутся.')
s = s.replace('// Токены НЕ публикуем в GitHub: заполняем прямо на хостинге, в менеджере файлов ispmanager.', '// Токены берутся из секретов GitHub Actions, в репозитории их нет.')
open(out, 'w', encoding='utf-8').write(s)
print('config.php собран')
