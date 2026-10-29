#!/bin/sh
# Serverdagi kunlik baza nusxalarini shu kompyuterga tortadi (tashqi backup).
# launchd har kuni 09:30 da va har tizimga kirishda ishga tushiradi.
set -e
DEST="$HOME/Documents/shajara_backups"
mkdir -p "$DEST"
/usr/bin/rsync -az -e "ssh -o BatchMode=yes -o ConnectTimeout=15" \
  root@109.94.173.189:/var/backups/shajara/ "$DEST/"
# 60 kundan eski lokal nusxalarni tozalash
find "$DEST" -name 'shajara-*.sql.gz' -mtime +60 -delete
echo "$(date '+%Y-%m-%d %H:%M') OK" >> "$DEST/pull.log"
