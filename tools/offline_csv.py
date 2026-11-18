#!/usr/bin/env python3
"""Офлайн-конверсии для Яндекс Метрики из таблицы Лидии «Заявки с сайта».

Запуск: python3 tools/offline_csv.py zayavki.xlsx out.csv
Берёт строки, где «Пришла на процедуру» = да и заполнен «Номер посетителя в Метрике».
Выход — CSV по справке Метрики (https://yandex.ru/support/metrica/ru/data/offline-conversion-data):
ClientId,Target,DateTime,Price,Currency; DateTime — Unix-время в секундах, часовой пояс счётчика (Москва).
Цель в кабинете Метрики: тип «JavaScript-событие», идентификатор procedure.
Учитываются только визиты не старше 21 дня — загружать раз в неделю.
Файл с данными клиентов в репозиторий НЕ класть.
"""
import csv, sys
from datetime import datetime
from zoneinfo import ZoneInfo

TZ = ZoneInfo('Europe/Moscow')
TARGET = 'procedure'

def rows(path):
    if path.lower().endswith('.xlsx'):
        from openpyxl import load_workbook
        ws = load_workbook(path, data_only=True).active
        it = ws.iter_rows(values_only=True)
        head = [str(h or '').strip() for h in next(it)]
        for r in it:
            yield dict(zip(head, r))
    else:
        with open(path, encoding='utf-8-sig', newline='') as f:
            sample = f.read(2048); f.seek(0)
            yield from csv.DictReader(f, dialect=csv.Sniffer().sniff(sample, ';,\t'))

def when(v):
    if isinstance(v, datetime): d = v
    else:
        v = str(v).strip()
        for fmt in ('%d.%m.%Y %H:%M', '%d.%m.%Y', '%Y-%m-%d %H:%M:%S', '%Y-%m-%d'):
            try: d = datetime.strptime(v, fmt); break
            except ValueError: pass
        else: raise ValueError(f'не понимаю дату: {v!r}')
    if d.hour == 0 and d.minute == 0: d = d.replace(hour=12)
    return int(d.replace(tzinfo=TZ).timestamp())

def main(src, dst):
    out, skipped = [], 0
    for r in rows(src):
        cid = ''.join(ch for ch in str(r.get('Номер посетителя в Метрике') or '') if ch.isdigit())
        came = str(r.get('Пришла на процедуру') or '').strip().lower() in ('да', 'yes', '1', '+')
        if not (cid and came and r.get('Дата процедуры')):
            skipped += 1; continue
        price = str(r.get('Сумма, ₽') or '').replace(' ', '').replace(' ', '').replace(',', '.')
        out.append([cid, TARGET, when(r['Дата процедуры']), price, 'RUB' if price else ''])
    with open(dst, 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f); w.writerow(['ClientId', 'Target', 'DateTime', 'Price', 'Currency']); w.writerows(out)
    print(f'в файл: {len(out)}, пропущено: {skipped}')

if __name__ == '__main__':
    main(*sys.argv[1:3])
