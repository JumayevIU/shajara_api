<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram Mini App autentifikatsiyasi.
 *
 * Frontend har bir so'rovda `Authorization: tma <initData>` yuboradi.
 * initData — Telegram imzolagan satr; HMAC orqali bot tokeni bilan
 * tekshiriladi (https://core.telegram.org/bots/webapps#validating-data).
 * Tasdiqlangan foydalanuvchi bazada upsert qilinadi.
 */
class TelegramAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = null;
        $auth = (string) $request->header('Authorization', '');

        if (str_starts_with($auth, 'tma ')) {
            $data = self::validateInitData(substr($auth, 4), config('services.telegram.bot_token'));
            if (! $data) {
                return response()->json(['message' => "Telegram ma'lumotlari tasdiqlanmadi"], 401);
            }
            $tgUser = json_decode($data['user'] ?? '{}', true);
            if (! isset($tgUser['id'])) {
                return response()->json(['message' => "Foydalanuvchi ma'lumoti topilmadi"], 401);
            }
            $user = User::updateOrCreate(
                ['tg_id' => $tgUser['id']],
                [
                    'first_name' => mb_substr($tgUser['first_name'] ?? 'Foydalanuvchi', 0, 60),
                    'username' => $tgUser['username'] ?? null,
                    'photo_url' => $tgUser['photo_url'] ?? null,
                ]
            );
        } elseif (str_starts_with($auth, 'Bearer ')) {
            // Mobil ilova tokeni (POST /api/auth/request oqimi orqali olingan)
            $row = \App\Models\ApiToken::where('token_hash', hash('sha256', substr($auth, 7)))
                ->whereNotNull('user_id')
                ->first();
            if (! $row) {
                return response()->json(['message' => "Token noto'g'ri yoki bekor qilingan"], 401);
            }
            $user = $row->user;
        } elseif (app()->environment(['local', 'testing']) && $request->hasHeader('X-Dev-User')) {
            // Faqat lokal ishlab chiqish: "12345:Ism" ko'rinishida soxta foydalanuvchi
            [$id, $name] = array_pad(explode(':', (string) $request->header('X-Dev-User'), 2), 2, '');
            if (! ctype_digit($id)) {
                return response()->json(['message' => 'X-Dev-User formati: "<raqam>:<ism>"'], 401);
            }
            $user = User::updateOrCreate(
                ['tg_id' => (int) $id],
                ['first_name' => mb_substr($name !== '' ? $name : 'Dev', 0, 60)]
            );
        }

        if (! $user) {
            return response()->json(['message' => 'Avtorizatsiya talab qilinadi (Telegram orqali oching)'], 401);
        }

        $request->attributes->set('tgUser', $user);

        return $next($request);
    }

    /** initData ni tekshiradi; muvaffaqiyatda maydonlar massivini, aks holda null qaytaradi. */
    public static function validateInitData(string $initData, ?string $botToken, int $maxAge = 86400): ?array
    {
        if (! $botToken || $initData === '') {
            return null;
        }
        parse_str($initData, $data);
        if (! isset($data['hash']) || ! is_string($data['hash'])) {
            return null;
        }
        $hash = $data['hash'];
        unset($data['hash']);
        ksort($data);
        $checkString = implode("\n", array_map(fn ($k) => $k.'='.$data[$k], array_keys($data)));
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        if (! hash_equals(hash_hmac('sha256', $checkString, $secretKey), $hash)) {
            return null;
        }
        if (! isset($data['auth_date']) || (time() - (int) $data['auth_date']) > $maxAge) {
            return null;
        }

        return $data;
    }
}
