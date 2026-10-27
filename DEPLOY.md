# Serverga joylash (VPS, nginx + PHP)

Front va back bitta loyihada: Laravel `/` da mini app sahifasini, `/api/...` da APIni beradi. Domen: **shajara.uz** (Ahost'dan olingan, A-yozuvi VPS'ga qaraydi).

## Talablar

- PHP 8.2+ (`php -v`), kengaytmalar: `pdo_sqlite` yoki `pdo_mysql`, `mbstring`, `openssl`
- Composer
- nginx + php-fpm (uzrict loyihalaringiz ishlab turgan server mos keladi)
- HTTPS **majburiy** — Telegram mini app faqat HTTPS URL ochadi (certbot bepul)

## DNS (Ahost)

Ahost kabinetida `shajara.uz` uchun DNS yozuvlari:

| Turi | Host | Qiymat |
|------|------|--------|
| A    | @    | VPS IP manzili |
| A    | www  | VPS IP manzili |

VPS IP — uzrict loyihalari turgan serverning IP'si (serverda `curl -4 ifconfig.me` ko'rsatadi). MX/TXT hozircha kerak emas.

Tekshirish: `dig +short shajara.uz` server IP'sini qaytarsa — tayyor (odatda 15 daqiqa – 2 soat ichida tarqaladi).

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
APP_URL=https://shajara.uz

# Boshlanishiga SQLite yetadi (alohida baza serveri kerak emas):
DB_CONNECTION=sqlite
# MySQL xohlasangiz: DB_CONNECTION=mysql + host/baza/parol

TELEGRAM_BOT_TOKEN=<BotFather bergan token>
TELEGRAM_BOT_USERNAME=shajaralarim_bot
TELEGRAM_APP_SHORT_NAME=shajara
```

```bash
# 4. Baza
touch database/database.sqlite   # SQLite tanlagan bo'lsangiz
php artisan migrate --force

# 5. Ruxsatlar va kesh
chown -R www-data:www-data storage bootstrap/cache database
php artisan config:cache && php artisan route:cache
```

## nginx vhost

`/etc/nginx/sites-available/shajara`:

```nginx
server {
    listen 80;
    server_name shajara.uz www.shajara.uz;
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
certbot --nginx -d shajara.uz -d www.shajara.uz
```

## Telegram'ga ulash

1. @BotFather → `/myapps` → Shajara → **Edit Web App URL** → `https://shajara.uz/`
2. Menu Button ishlatilsa: `/mybots` → Bot Settings → Menu Button → shu URL
3. Tekshirish: `t.me/shajaralarim_bot/shajara` oching — daraxt endi umumiy bazada saqlanadi, sozlamalarda taklif havolasi chiqadi

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
