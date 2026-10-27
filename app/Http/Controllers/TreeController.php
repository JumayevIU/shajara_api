<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Tree;
use App\Models\TreeMember;
use App\Services\TreeState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TreeController extends Controller
{
    /** GET /api/state — foydalanuvchining joriy holati. */
    public function state(Request $request)
    {
        return response()->json(TreeState::forUser($request->attributes->get('tgUser')));
    }

    /** POST /api/tree — yangi daraxt: asoschi o'z kartasi bilan boshlaydi. */
    public function store(Request $request)
    {
        $user = $request->attributes->get('tgUser');
        if (TreeMember::where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Siz allaqachon bitta daraxtdasiz'], 422);
        }

        $data = $request->validate([
            'name' => 'required|string|max:60',
            'g' => 'required|in:m,f',
            'by' => 'nullable|integer|min:1850|max:'.date('Y'),
        ]);

        $member = DB::transaction(function () use ($user, $data) {
            $tree = Tree::create([
                'invite_code' => Tree::freshInviteCode(),
                'owner_id' => $user->id,
            ]);
            $person = Person::create([
                'tree_id' => $tree->id,
                'name' => $data['name'],
                'gender' => $data['g'],
                'birth_year' => $data['by'] ?? null,
                'alive' => true,
                'created_by' => $user->id,
            ]);
            $tree->update(['root_person_id' => $person->id]);

            return TreeMember::create([
                'tree_id' => $tree->id,
                'user_id' => $user->id,
                'person_id' => $person->id, // asoschi o'z kartasini avtomatik egallaydi
            ]);
        });

        return response()->json(TreeState::build($member), 201);
    }

    /** GET /api/invites/{code} — qo'shilishdan oldingi ko'rinish. */
    public function invitePreview(string $code)
    {
        $tree = Tree::where('invite_code', strtoupper($code))->first();
        if (! $tree) {
            return response()->json(['message' => 'Taklif havolasi topilmadi yoki eskirgan'], 404);
        }

        return response()->json([
            'owner_name' => $tree->owner?->first_name ?? 'Oila',
            'persons_count' => Person::where('tree_id', $tree->id)->count(),
            'members_count' => TreeMember::where('tree_id', $tree->id)->count(),
        ]);
    }

    /** POST /api/tree/join — taklif kodi orqali qo'shilish. */
    public function join(Request $request)
    {
        $user = $request->attributes->get('tgUser');
        $data = $request->validate(['code' => 'required|string|max:16']);

        $tree = Tree::where('invite_code', strtoupper($data['code']))->first();
        if (! $tree) {
            return response()->json(['message' => 'Taklif havolasi topilmadi yoki eskirgan'], 404);
        }

        $existing = TreeMember::where('user_id', $user->id)->first();
        if ($existing) {
            if ($existing->tree_id === $tree->id) {
                return response()->json(TreeState::build($existing));
            }

            return response()->json(['message' => 'Siz allaqachon boshqa daraxtdasiz'], 422);
        }

        $member = TreeMember::create([
            'tree_id' => $tree->id,
            'user_id' => $user->id,
        ]);

        return response()->json(TreeState::build($member), 201);
    }
}
