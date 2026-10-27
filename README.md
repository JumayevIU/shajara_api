# Shajara API

[Shajara mini app](https://github.com/JumayevIU/shajara) uchun Laravel backend: umumiy baza, Telegram autentifikatsiyasi va taklif havolalari.

## Arxitektura

- **Auth** — Sessiya ham, parol ham yo'q. Frontend har so'rovda `Authorization: tma <initData>` yuboradi; [TelegramAuth middleware](app/Http/Middleware/TelegramAuth.php) uni bot tokeni bilan HMAC orqali tekshiradi (rasmiy algoritm), foydalanuvchini bazada upsert qiladi. 24 soatdan eski initData rad etiladi.
- **Holat** — har bir yozuvdan keyin server to'liq daraxt holatini qaytaradi ([TreeState](app/Services/TreeState.php)); frontend shunchaki qabul qilib chizadi. Daraxtlar kichik bo'lgani uchun bu eng sodda va xatosiz sinxronlashuv.
- **Model** — `trees` (taklif kodi bilan) → `persons` (ota/ona FK) + `spouse_links` (er-xotin) + `tree_members` (foydalanuvchi ↔ daraxt, "Bu — men" kartasi). MVPda har foydalanuvchi bitta daraxtda.

## Endpointlar

| Metod | Yo'l | Vazifa |
|---|---|---|
| GET | `/api/state` | Joriy holat (daraxt bo'lmasa `in_tree:false`) |
| POST | `/api/tree` | Yangi daraxt — asoschi o'z kartasi bilan |
| GET | `/api/invites/{code}` | Taklifni qo'shilishdan oldin ko'rish |
| POST | `/api/tree/join` | Kod orqali qo'shilish |
| POST | `/api/persons` | Qarindosh qo'shish (`role`: father/mother/spouse/child/sibling, `base_id` ga nisbatan) |
| PATCH | `/api/persons/{id}` | Tahrirlash |
| DELETE | `/api/persons/{id}` | O'chirish (biriktirilgan yoki daraxtni uzadigan kartalar himoyalangan) |
| POST | `/api/persons/{id}/claim` | "Bu — men" — kartani o'ziga biriktirish |

## Ishga tushirish

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve
```

`.env` da to'ldiring:

```
TELEGRAM_BOT_TOKEN=      # BotFather bergan token
TELEGRAM_BOT_USERNAME=   # masalan shajara_oila_bot (@siz)
TELEGRAM_APP_SHORT_NAME=shajara
```

`TELEGRAM_BOT_USERNAME` to'ldirilgach taklif havolalari `https://t.me/<bot>/shajara?startapp=<kod>` ko'rinishida chiqadi.

## Lokal sinov (Telegramsiz)

`APP_ENV=local` da `X-Dev-User: 1:Ilhom` sarlavhasi soxta foydalanuvchi yaratadi. Frontendda: `index.html?api=http://127.0.0.1:8000&dev=1:Ilhom`. Bu yo'l production muhitida o'chiq (test bilan qoplangan).

## Testlar

```bash
php artisan test
```

[tests/Feature/ShajaraApiTest.php](tests/Feature/ShajaraApiTest.php) — initData imzosini tekshirish (haqiqiy HMAC bilan), daraxt yaratish, qarindosh qo'shish qoidalari, taklif/qo'shilish/claim oqimi, o'chirish himoyalari.

## Frontendni ulash

`shajara` repodagi `index.html` da `API_DEFAULT` ga shu APIning joylangan HTTPS manzilini yozing. CORS hozircha hamma originlarga ochiq (auth initData orqali bo'lgani uchun xavfsiz); xohlasangiz `config/cors.php` da faqat GitHub Pages originiga toraytiring.
