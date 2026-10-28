# Shajara API — mobil ilova uchun hujjat

Base URL: `https://xn--ajara-idb.uz` (brauzerda şajara.uz ko'rinishida)

Postman collection: [docs/Shajara.postman_collection.json](docs/Shajara.postman_collection.json)
(Postman → Import → shu faylni tanlang; `base_url`, `token` o'zgaruvchilari tayyor.)

## Asosiy tamoyil: to'liq holat (full-state sync)

Har bir o'zgartiruvchi so'rov (POST/PATCH/DELETE) javobida **daraxtning to'liq holati**
qaytadi — mobil ilova javobni olib ekranni to'liq qayta chizsa kifoya. Lokal diff,
merge yoki sinxronlash logikasi kerak emas.

## Autentifikatsiya (mobil oqim)

Hisob — foydalanuvchining Telegram akkauntiga bog'lanadi (mini app bilan bitta hisob,
bitta daraxt). Parol yo'q.

```
1. POST /api/auth/request          {"device": "iPhone 15"}        (auth'siz)
   → 201 {"code":"AB2CD3EF", "deep_link":"https://t.me/shajaralarim_bot?start=auth_AB2CD3EF", "expires_in":600}

2. deep_link ni foydalanuvchiga oching (Telegram ochiladi, Start bosadi,
   bot "✅ Mobil ilova ulandi" deb javob beradi)

3. GET /api/auth/token?code=AB2CD3EF   har 2–3 soniyada so'rab turing (auth'siz)
   → 200 {"status":"pending"}                — hali bosilmagan
   → 200 {"status":"ok","token":"<48 belgi>"} — TOKEN FAQAT SHU BIR JAVOBDA!
   → 410                                      — token allaqachon olingan
   → 404                                      — kod eskirgan (10 daqiqa)

4. Tokenni xavfsiz saqlang (Keychain/Keystore). Keyin HAMMA so'rovlarda:
   Authorization: Bearer <token>
```

Token muddatsiz; noto'g'ri/bekor qilingan token → `401`.

> Ma'lumot uchun: mini app o'z so'rovlarini `Authorization: tma <initData>`
> bilan yuboradi — mobil ilovaga bu kerak emas.

## Shaxs (person) obyekti

```json
{
  "id": "p12",
  "name": "Baxtiyor",
  "g": "m",                 // m | f
  "by": 1965,               // tug'ilgan yil (null bo'lishi mumkin)
  "dy": 2020,               // vafot yili (alive=false bo'lsa)
  "alive": false,
  "bio": "Matematika o'qituvchisi edi...",   // null bo'lishi mumkin
  "photos": [ {"id": 4, "url": "https://.../storage/persons/12/abc.jpg"} ],
  "fatherId": "p2",         // null bo'lishi mumkin
  "motherId": null,
  "spouseIds": ["p4"],
  "claimed": true           // biror foydalanuvchi "bu — men" degan
}
```

## Holat javobi (GET /api/state va har yozuvdan keyin)

```json
{
  "in_tree": true,
  "state": {
    "persons": { "p1": { ... }, "p2": { ... } },
    "root_id": "p1",          // daraxt ildizi
    "my_person_id": "p1"      // mening kartam (null — hali belgilamagan)
  },
  "invite_url": "https://t.me/shajaralarim_bot/shajara?startapp=KOD",
  "invite_code": "KOD",
  "is_owner": true,
  "members": [ {"id":1, "name":"Ilhom", "person_id":"p1", "self":true} ]  // faqat egaga
}
```

Daraxtda bo'lmasa: `{"in_tree": false}`.

## Endpointlar

Hammasi `Authorization: Bearer <token>` bilan (auth bo'limidagilardan tashqari).

| Metod | Yo'l | Vazifa |
|---|---|---|
| POST | `/api/auth/request` | Ulanish kodi olish (auth'siz) |
| GET  | `/api/auth/token?code=` | Tokenni olish — bir marta (auth'siz) |
| GET  | `/api/state` | To'liq holat |
| POST | `/api/tree` | Daraxt yaratish (o'zidan boshlaydi) |
| GET  | `/api/invites/{code}?person=p3` | Taklif preview (person — ixtiyoriy) |
| POST | `/api/tree/join` | Qo'shilish `{code, person_id?}` |
| POST | `/api/tree/leave` | Chiqish (yolg'iz ega — daraxtni o'chiradi) |
| POST | `/api/tree/rotate-code` | Yangi taklif kodi (faqat ega) |
| DELETE | `/api/tree/members/{id}` | A'zoni chiqarish (faqat ega) |
| POST | `/api/persons` | Qarindosh qo'shish |
| POST | `/api/persons/{id}/link` | Mavjud odamni ota/ona/juft qilib bog'lash |
| PATCH | `/api/persons/{id}` | Tahrirlash (bio shu yerda) |
| DELETE | `/api/persons/{id}` | O'chirish (himoyalar bilan) |
| POST | `/api/persons/{id}/claim` | "Bu — men" |
| POST | `/api/persons/{id}/photos` | Rasm yuklash (multipart `photo`) |
| DELETE | `/api/photos/{id}` | Rasmni o'chirish |
| POST | `/api/export` | PDF saqlash → bir martalik URL (ixtiyoriy) |

### Qarindosh qo'shish — POST /api/persons

```json
{
  "role": "father",          // father | mother | spouse | child | sibling
  "base_id": "p1",           // kimga nisbatan
  "name": "Baxtiyor",
  "g": "m",                  // father/mother'da avtomatik to'g'rilanadi
  "by": 1965, "dy": null, "alive": true,
  "other_parent_id": "p4"    // faqat role=child: ikkinchi ota-ona (ixtiyoriy)
}
```

Qoidalar (422): ota/ona allaqachon kiritilgan bo'lsa qo'shib bo'lmaydi;
`sibling` uchun base'ning ota yoki onasi bo'lishi shart.

### Rasmlar — POST /api/persons/{id}/photos

- `multipart/form-data`, maydon: **photo**
- Formatlar: **jpeg, png, webp** (iOS HEIC'ni avval JPEG'ga aylantiring)
- Hajm: **8 MB** gacha; bir odamga **ko'pi bilan 10 ta** (11-chisi 422)
- Javob: `201` + to'liq holat — `persons[id].photos` da `{id, url}`
- O'chirish: `DELETE /api/photos/{photoId}` (id — photos massividagi raqam)

### Xato kodlari

| Kod | Ma'nosi |
|---|---|
| 401 | Token yo'q/noto'g'ri — auth oqimini qaytadan o'tkazing |
| 403 | Faqat daraxt egasi qila oladigan amal |
| 404 | Topilmadi (karta, kod, rasm) |
| 410 | Auth token allaqachon olingan — qayta ulang |
| 422 | Qoida buzildi — javobdagi `message` (o'zbekcha) foydalanuvchiga ko'rsatsa bo'ladi |
| 429 | Juda ko'p so'rov (auth endpointlarida throttle bor) |

Barcha xatolar: `{"message": "..."}` — matnlar o'zbekcha, UI'da bevosita ko'rsatish mumkin.

## Muhim qoidalar (mahsulot mantig'i)

- Bir foydalanuvchi ayni paytda **faqat bitta daraxtda** bo'la oladi
- `claimed` kartani boshqa foydalanuvchi egallay olmaydi va o'chirib bo'lmaydi
- O'chirish daraxtni uzib qo'ysa — rad etiladi (chekkadan o'chiriladi)
- Taklif havolasi: `t.me/shajaralarim_bot/shajara?startapp=KOD` (umumiy) yoki
  `...?startapp=KOD_p12` (shaxsiy — p12 kartasi uchun); mobil ilova join'da
  `person_id` sifatida yuboradi
