<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Mobil ilova autentifikatsiyasi.
 *
 * Oqim:
 *  1. POST /api/auth/request  → {code, deep_link}
 *  2. Foydalanuvchi deep_link orqali botga o'tib Start bosadi
 *     (webhook kodga foydalanuvchini bog'lab token yaratadi)
 *  3. GET /api/auth/token?code=... → {status:"ok", token} (token BIR MARTA beriladi)
 *  4. Keyingi so'rovlar: Authorization: Bearer <token>
 */
class AuthController extends Controller
{
    public function request(Request $request)
    {
        $data = $request->validate(['device' => 'nullable|string|max:80']);

        // Eskirgan, bog'lanmagan kodlarni tozalaymiz
        ApiToken::whereNull('claimed_at')->where('created_at', '<', now()->subMinutes(15))->delete();

        do {
            $code = strtoupper(Str::random(8));
            $code = strtr($code, ['0' => '2', 'O' => '3', '1' => '4', 'I' => '5', 'L' => '6']);
        } while (ApiToken::where('code', $code)->exists());

        ApiToken::create(['code' => $code, 'device' => $data['device'] ?? null]);

        return response()->json([
            'code' => $code,
            'deep_link' => 'https://t.me/'.config('services.telegram.bot_username').'?start=auth_'.$code,
            'expires_in' => 600,
        ], 201);
    }

    public function token(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:16']);
        $row = ApiToken::where('code', strtoupper($data['code']))->first();

        if (! $row || (! $row->claimed_at && $row->created_at < now()->subMinutes(10))) {
            return response()->json(['message' => 'Kod topilmadi yoki eskirgan'], 404);
        }
        if (! $row->claimed_at) {
            return response()->json(['status' => 'pending']);
        }
        if ($row->token_plain === null) {
            return response()->json(['message' => 'Token allaqachon olingan — qaytadan ulang'], 410);
        }

        $token = $row->token_plain;
        $row->update(['token_plain' => null]); // faqat bir marta beriladi

        return response()->json(['status' => 'ok', 'token' => $token]);
    }
}
