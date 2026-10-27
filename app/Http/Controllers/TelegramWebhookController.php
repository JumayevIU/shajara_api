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
}
