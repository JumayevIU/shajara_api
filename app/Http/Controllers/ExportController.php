<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    /** POST /api/export — mijoz yasagan PDF'ni vaqtincha saqlaydi.
     *  Telegram ichida yuklab olish uchun kerak: tg.downloadFile faqat URL oladi. */
    public function store(Request $request)
    {
        $raw = $request->getContent();
        if (strlen($raw) < 100 || strlen($raw) > 20 * 1024 * 1024) {
            return response()->json(['message' => 'Fayl hajmi noto\'g\'ri'], 422);
        }
        if (! str_starts_with($raw, '%PDF-')) {
            return response()->json(['message' => 'Faqat PDF qabul qilinadi'], 422);
        }

        // 1 soatdan oshgan eski eksportlarni tozalaymiz
        foreach (Storage::files('exports') as $f) {
            if (Storage::lastModified($f) < time() - 3600) {
                Storage::delete($f);
            }
        }

        $token = Str::random(40);
        Storage::put('exports/'.$token.'.pdf', $raw);

        return response()->json(['url' => url('/export/'.$token)]);
    }

    /** GET /export/{token} — faylni BIR MARTA beradi va o'chiradi (token tasodifiy). */
    public function show(string $token)
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            abort(404);
        }
        $path = 'exports/'.$token.'.pdf';
        if (! Storage::exists($path)) {
            abort(404);
        }
        $content = Storage::get($path);
        Storage::delete($path);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="shajara.pdf"',
        ]);
    }
}
