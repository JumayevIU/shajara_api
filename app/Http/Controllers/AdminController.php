<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\Tree;
use App\Models\TreeMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Admin panel API — barcha endpointlar AdminAuth (X-Admin-Key) ortida. */
class AdminController extends Controller
{
    /** GET /api/admin/overview — umumiy raqamlar. */
    public function overview()
    {
        return response()->json([
            'foydalanuvchilar' => User::count(),
            'daraxtlar' => Tree::count(),
            'kartalar' => Person::count(),
            'rasmlar' => PersonPhoto::count(),
            'bugun_qoshilganlar' => User::whereDate('created_at', today())->count(),
            'hafta_qoshilganlar' => User::where('created_at', '>=', now()->subDays(7))->count(),
        ]);
    }

    /** GET /api/admin/users — kim foydalanmoqda, nima kiritmoqda. */
    public function users()
    {
        $members = TreeMember::get()->keyBy('user_id');
        $created = Person::selectRaw('created_by, COUNT(*) c')->groupBy('created_by')->pluck('c', 'created_by');
        $personNames = Person::pluck('name', 'id');
        $owners = Tree::pluck('owner_id', 'id');

        $out = User::orderByDesc('id')->get()->map(function ($u) use ($members, $created, $personNames, $owners) {
            $m = $members[$u->id] ?? null;

            return [
                'id' => $u->id,
                'ism' => $u->first_name,
                'username' => $u->username,
                'qoshilgan' => $u->created_at?->format('Y-m-d H:i'),
                'daraxt' => $m ? ('#'.$m->tree_id.(($owners[$m->tree_id] ?? null) === $u->id ? ' · ega' : '')) : null,
                'kartasi' => $m && $m->person_id ? ($personNames[$m->person_id] ?? null) : null,
                'kiritgan_kartalari' => (int) ($created[$u->id] ?? 0),
            ];
        });

        return response()->json(['users' => $out]);
    }

    /** GET /api/admin/trees — daraxtlar ro'yxati. */
    public function trees()
    {
        $userNames = User::pluck('first_name', 'id');
        $members = TreeMember::selectRaw('tree_id, COUNT(*) c')->groupBy('tree_id')->pluck('c', 'tree_id');
        $persons = Person::selectRaw('tree_id, COUNT(*) c')->groupBy('tree_id')->pluck('c', 'tree_id');

        $out = Tree::orderByDesc('id')->get()->map(fn ($t) => [
            'id' => $t->id,
            'ega' => $userNames[$t->owner_id] ?? '—',
            'azolar' => (int) ($members[$t->id] ?? 0),
            'kartalar' => (int) ($persons[$t->id] ?? 0),
            'yaratilgan' => $t->created_at?->format('Y-m-d'),
        ]);

        return response()->json(['trees' => $out]);
    }

    /** POST /api/admin/message — bitta foydalanuvchiga xabar. */
    public function message(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'text' => 'required|string|max:3500',
        ]);
        $user = User::find($data['user_id']);
        if (! $user) {
            return response()->json(['message' => 'Foydalanuvchi topilmadi'], 404);
        }

        $ok = $this->sendTo($user->tg_id, $data['text']);

        return response()->json(['sent' => $ok ? 1 : 0, 'failed' => $ok ? 0 : 1]);
    }

    /** POST /api/admin/broadcast — hammaga xabar. Natija: kimga yetdi/yetmadi. */
    public function broadcast(Request $request)
    {
        $data = $request->validate(['text' => 'required|string|max:3500']);

        $sent = 0;
        $failed = [];
        foreach (User::whereNotNull('tg_id')->get() as $u) {
            if ($this->sendTo($u->tg_id, $data['text'])) {
                $sent++;
            } else {
                // Telegram qoidasi: bot faqat o'ziga avval yozgan odamga xabar yubora oladi
                $failed[] = $u->first_name.($u->username ? ' (@'.$u->username.')' : '');
            }
            usleep(50000); // Telegram limitlariga hurmat (soniyasiga ~20 ta)
        }

        return response()->json(['sent' => $sent, 'failed' => count($failed), 'failed_users' => $failed]);
    }

    private function sendTo($chatId, string $text): bool
    {
        try {
            $r = Http::asJson()->post(
                'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
                ['chat_id' => $chatId, 'text' => $text]
            );

            return $r->ok() && $r->json('ok') === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
