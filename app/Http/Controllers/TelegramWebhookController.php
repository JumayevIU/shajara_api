<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramWebhookController extends Controller
{
    /** POST /api/telegram/webhook — bot xabarlari.
     *  Shaxsiy chatdagi har qanday xabarga (shu jumladan /start) ilovani
     *  ochadigan tugmali xabar bilan javob beradi. */
    public function handle(Request $request)
    {
        $secret = config('services.telegram.webhook_secret');
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (! $secret || ! hash_equals($secret, $given)) {
            return response()->json(['ok' => false], 403);
        }

        $msg = $request->input('message');
        $chatId = $msg['chat']['id'] ?? null;
        if (! $chatId || ($msg['chat']['type'] ?? '') !== 'private') {
            return response()->json(['ok' => true]); // guruhlar va boshqa update turlariga indamaymiz
        }

        // Mobil ilovani bog'lash: /start auth_<KOD>
        $text = trim((string) ($msg['text'] ?? ''));
        if (preg_match('/^\/start\s+auth_([A-Za-z0-9]{6,16})$/', $text, $m)) {
            return $this->bindMobile($chatId, strtoupper($m[1]), $msg['from'] ?? []);
        }

        $appUrl = 'https://t.me/'.config('services.telegram.bot_username')
            .'/'.config('services.telegram.app_short_name', 'shajara');

        Http::asJson()->post(
            'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
            [
                'chat_id' => $chatId,
                'text' => "Assalomu alaykum! 🌳\n\nShajara — oila daraxtingizni tuzing: ota-ona, bobo-buvi, farzandlar... Qarindoshlaringizni taklif qilib, bitta umumiy shajarani birga to'ldirasiz.",
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => '🌳 Shajarani ochish', 'url' => $appUrl],
                    ]],
                ],
            ]
        );

        return response()->json(['ok' => true]);
    }

    /** Mobil ilova kodi orqali foydalanuvchini bog'lab, token yaratadi. */
    private function bindMobile($chatId, string $code, array $from)
    {
        $row = \App\Models\ApiToken::where('code', $code)
            ->whereNull('claimed_at')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->first();

        $ok = false;
        if ($row && isset($from['id'])) {
            $user = \App\Models\User::updateOrCreate(
                ['tg_id' => $from['id']],
                [
                    'first_name' => mb_substr($from['first_name'] ?? 'Foydalanuvchi', 0, 60),
                    'username' => $from['username'] ?? null,
                ]
            );
            $plain = \Illuminate\Support\Str::random(48);
            $row->update([
                'user_id' => $user->id,
                'claimed_at' => now(),
                'token_hash' => hash('sha256', $plain),
                'token_plain' => $plain,
            ]);
            $ok = true;
        }

        Http::asJson()->post(
            'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
            [
                'chat_id' => $chatId,
                'text' => $ok
                    ? "✅ Mobil ilova muvaffaqiyatli ulandi!\n\nEndi ilovaga qaytishingiz mumkin."
                    : "Ulanish kodi eskirgan yoki noto'g'ri — mobil ilovadan qaytadan urinib ko'ring.",
            ]
        );

        return response()->json(['ok' => true]);
    }
}
