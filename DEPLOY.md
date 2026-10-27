# Serverga joylash (VPS, nginx + PHP)

Front va back bitta loyihada: Laravel `/` da mini app sahifasini, `/api/...` da APIni beradi. Domen: **şajara.uz** (Ahost'dan olingan IDN domen; texnik/punycode yozuvi — `xn--ajara-idb.uz`, nginx va certbot'da shu ishlatiladi). Server: `109.94.173.189`.

## Talablar

- PHP 8.2+ (`php -v`), kengaytmalar: `pdo_mysql`, `mbstring`, `openssl`
- Composer
- nginx + php-fpm (uzrict loyihalaringiz ishlab turgan server mos keladi)
- HTTPS **majburiy** — Telegram mini app faqat HTTPS URL ochadi (certbot bepul)

## DNS (Ahost)

Ahost kabinetida (DNS xosting → Редактор зон) bitta o'zgarish: `@` A-yozuvining RDATA qiymatini `109.94.173.189` ga almashtirish va Save Changes. `www` CNAME `@` ga ergashadi — tegilmaydi; mail/ftp/MX/TXT qatorlari ham qoladi.

Tekshirish: `dig +short xn--ajara-idb.uz` `109.94.173.189` ni qaytarsa — tayyor (TTL 14400, eng ko'pi 4 soat kutiladi).

## Qadamlar

```bash
# 1. Kodni olish
cd /var/www
git clone https://github.com/JumayevIU/shajara_api.git shajara
cd shajara

# 2. Bog'liqliklar
composer install --no-dev --optimize-autoloader

# 3. Sozlash
cp .env.example .env
php artisan key:generate
```

`.env` da to'ldiring:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://xn--ajara-idb.uz

# Production: MySQL (serverda shunday o'rnatilgan, 2026-10-27)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=shajara
DB_USERNAME=shajara
DB_PASSWORD=<kuchli parol — serverda `openssl rand -hex 16` bilan yaratilgan>

TELEGRAM_BOT_TOKEN=<BotFather bergan token>
TELEGRAM_BOT_USERNAME=shajaralarim_bot
TELEGRAM_APP_SHORT_NAME=shajara
```

```bash
# 4. Baza (MySQL)
mysql -e "CREATE DATABASE shajara CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'shajara'@'localhost' IDENTIFIED BY '<parol>'; GRANT ALL PRIVILEGES ON shajara.* TO 'shajara'@'localhost';"
php artisan migrate --force

# 5. Ruxsatlar va kesh
chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache && php artisan route:cache
```

## nginx vhost

`/etc/nginx/sites-available/shajara`:

```nginx
server {
    listen 80;
    server_name xn--ajara-idb.uz www.xn--ajara-idb.uz;
    root /var/www/shajara/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;  # php -v ga moslang
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/shajara /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# HTTPS (DNS A-yozuvi serverga qaragach):
certbot --nginx -d xn--ajara-idb.uz -d www.xn--ajara-idb.uz
```

## Telegram'ga ulash

1. @BotFather → `/myapps` → Shajara → **Edit Web App URL** → `https://xn--ajara-idb.uz/` (brauzer uni şajara.uz ko'rinishida ko'rsatadi)
2. Menu Button ishlatilsa: `/mybots` → Bot Settings → Menu Button → shu URL
3. Tekshirish: `t.me/shajaralarim_bot/shajara` oching — daraxt endi umumiy bazada saqlanadi, sozlamalarda taklif havolasi chiqadi

## Backup va tiklash

Kunlik backup o'rnatilgan (2026-10-27): har kuni 02:30 da `/usr/local/bin/shajara-backup.sh`
(cron: `/etc/cron.d/shajara-backup`) bazadan siqilgan nusxa oladi, 14 kunlik tarix saqlanadi.

```bash
# Nusxalar ro'yxati
ls -lh /var/backups/shajara/

# Qo'lda darhol nusxa olish
/usr/local/bin/shajara-backup.sh

# TIKLASH — hozirgi baza o'rniga tanlangan kundagi nusxani qaytaradi (EHTIYOT!)
gunzip -c /var/backups/shajara/shajara-2026-10-27.sql.gz | mysql shajara
```

Tiklashda sanani keragiga almashtiring. Tiklash hozirgi ma'lumotni o'chirib, nusxadagisini qo'yadi —
avval `/usr/local/bin/shajara-backup.sh` bilan joriy holatdan ham nusxa olib qo'ygan ma'qul.

## Yangilash (keyingi deploylar)

```bash
cd /var/www/shajara
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache
```

## Frontend o'zgarganda

Frontend manbasi hozircha `shajara` repodagi `index.html`. O'zgartirilgach:

```bash
bin/sync-frontend.sh            # public/app.html ni yangilaydi
git add public/app.html && git commit -m "Frontend sync" && git push
```

GitHub Pages (https://jumayeviu.github.io/shajara/) lokal-rejim demo sifatida qolaveradi; asosiy manzil — server.
