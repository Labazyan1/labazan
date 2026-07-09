#!/usr/bin/env python3
"""Упаковка dist/ в деплой-архив для Beget.

Правило агентства: архив = ОДНА корневая папка с именем проекта (labazan-site/),
внутри — содержимое dist/. Пути прямыми слэшами (Beget/Unix). lead-config.php
в архив НЕ попадает (его вычищает `npm run build` через strip-config.mjs) —
боевой конфиг живёт только на сервере и не перезатирается заливкой.

Запуск (после `npm run build`):
    python scripts/package.py
Результат: <Рабочий стол>/labazan-site-YYYYMMDD-HHMM.zip
"""
import os
import sys
import zipfile
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST = os.path.join(ROOT, "dist")
ROOT_FOLDER = "labazan-site"  # единственная корневая папка в архиве

# Страховка: даже если что-то просочилось — не кладём секретный конфиг в архив.
EXCLUDE_NAMES = {"lead-config.php"}

if not os.path.isdir(DIST):
    sys.exit("ERROR: нет dist/. Сначала выполни `npm run build`.")

desktop = os.path.join(os.path.expanduser("~"), "Desktop")
if not os.path.isdir(desktop):
    desktop = ROOT  # фолбэк, если Desktop не найден
stamp = datetime.now().strftime("%Y%m%d-%H%M")
out_zip = os.path.join(desktop, f"{ROOT_FOLDER}-{stamp}.zip")

count = 0
skipped = []
with zipfile.ZipFile(out_zip, "w", zipfile.ZIP_DEFLATED) as z:
    for dirpath, _dirs, files in os.walk(DIST):
        for name in files:
            if name in EXCLUDE_NAMES:
                skipped.append(name)
                continue
            abs_path = os.path.join(dirpath, name)
            rel = os.path.relpath(abs_path, DIST).replace(os.sep, "/")
            arcname = f"{ROOT_FOLDER}/{rel}"  # прямые слэши, одна корневая папка
            z.write(abs_path, arcname)
            count += 1

print(f"OK: {out_zip}")
print(f"файлов в архиве: {count}, корневая папка: {ROOT_FOLDER}/")
if skipped:
    print(f"исключено (секреты): {', '.join(sorted(set(skipped)))}")
print("\nЗаливка на Beget: извлечь архив -> переместить СОДЕРЖИМОЕ папки")
print(f"{ROOT_FOLDER}/ в public_html (перезаписать файлы сайта; lead-config.php на сервере не трогать).")
