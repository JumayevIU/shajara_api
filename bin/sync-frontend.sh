#!/bin/sh
# Frontend manbasi (shajara repo) dan mini app sahifasini shu loyihaga ko'chiradi.
# Farq bitta: Laravel bilan bir xil domenda ishlagani uchun onlayn rejim
# API_BASE talab qilmaydi (nisbiy /api yo'llari ishlatiladi).
set -e
SRC="${1:-$HOME/Documents/projects/shajara/index.html}"
DST="$(dirname "$0")/../public/app.html"

sed 's/online.candidate = !!API_BASE && !!(tg || DEV_USER);/online.candidate = !!(tg || DEV_USER);/' "$SRC" > "$DST"

if ! grep -q 'online.candidate = !!(tg || DEV_USER);' "$DST"; then
  echo "XATO: candidate qatori topilmadi — index.html o'zgargan bo'lishi mumkin" >&2
  exit 1
fi
echo "OK: $DST yangilandi"
