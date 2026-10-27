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

    /** GET /api/invites/{code} — qo'shilishdan oldingi ko'rinish.
     *  ?person=p12 bo'lsa (shaxsiy havola) — karta ma'lumotini ham qo'shadi. */
    public function invitePreview(Request $request, string $code)
    {
        $tree = Tree::where('invite_code', strtoupper($code))->first();
        if (! $tree) {
            return response()->json(['message' => 'Taklif havolasi topilmadi yoki eskirgan'], 404);
        }

        $out = [
            'owner_name' => $tree->owner?->first_name ?? 'Oila',
            'persons_count' => Person::where('tree_id', $tree->id)->count(),
            'members_count' => TreeMember::where('tree_id', $tree->id)->count(),
        ];

        if ($pid = $request->query('person')) {
            $person = Person::where('tree_id', $tree->id)->find((int) ltrim($pid, 'p'));
            if ($person) {
                $out['person'] = [
                    'id' => 'p'.$person->id,
                    'name' => $person->name,
                    'g' => $person->gender,
                    'by' => $person->birth_year,
                    'claimed' => TreeMember::where('tree_id', $tree->id)
                        ->where('person_id', $person->id)->exists(),
                ];
            }
        }

        return response()->json($out);
    }

    /** POST /api/tree/join — taklif kodi orqali qo'shilish. */
    public function join(Request $request)
    {
        $user = $request->attributes->get('tgUser');
        $data = $request->validate([
            'code' => 'required|string|max:16',
            'person_id' => 'nullable|string|max:16', // shaxsiy havola: darhol egallanadigan karta
        ]);

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

        $notice = null;
        if (! empty($data['person_id'])) {
            $pid = (int) ltrim($data['person_id'], 'p');
            $person = Person::where('tree_id', $tree->id)->find($pid);
            $taken = $person && TreeMember::where('tree_id', $tree->id)
                ->where('person_id', $pid)->exists();
            if ($person && ! $taken) {
                $member->update(['person_id' => $pid]);
            } else {
                $notice = "Havola mo'ljallangan karta band ekan — daraxtdan o'zingizni topib belgilang";
            }
        }

        $resp = TreeState::build($member);
        if ($notice) {
            $resp['notice'] = $notice;
        }

        return response()->json($resp, 201);
    }

    /** DELETE /api/tree/members/{id} — a'zoni daraxtdan chiqarish (faqat ega).
     *  Kartasi daraxtda qoladi, lekin bo'shaydi; chiqarilgan odam daraxtni boshqa ko'rmaydi. */
    public function removeMember(Request $request, string $id)
    {
        $user = $request->attributes->get('tgUser');
        $member = TreeMember::where('user_id', $user->id)->first();
        if (! $member) {
            return response()->json(['message' => 'Siz daraxtda emassiz'], 422);
        }
        $tree = Tree::findOrFail($member->tree_id);
        if ($tree->owner_id !== $user->id) {
            return response()->json(['message' => 'Faqat daraxt egasi a\'zolarni chiqara oladi'], 403);
        }
        $target = TreeMember::where('tree_id', $tree->id)->where('id', (int) $id)->first();
        if (! $target) {
            return response()->json(['message' => 'A\'zo topilmadi'], 404);
        }
        if ($target->id === $member->id) {
            return response()->json(['message' => 'O\'zingizni chiqara olmaysiz'], 422);
        }

        $target->delete();

        return response()->json(TreeState::build($member));
    }

    /** POST /api/tree/rotate-code — yangi taklif kodi (eski havolalar o'ladi, faqat ega). */
    public function rotateCode(Request $request)
    {
        $user = $request->attributes->get('tgUser');
        $member = TreeMember::where('user_id', $user->id)->first();
        if (! $member) {
            return response()->json(['message' => 'Siz daraxtda emassiz'], 422);
        }
        $tree = Tree::findOrFail($member->tree_id);
        if ($tree->owner_id !== $user->id) {
            return response()->json(['message' => 'Faqat daraxt egasi havolani yangilay oladi'], 403);
        }

        $tree->update(['invite_code' => Tree::freshInviteCode()]);

        return response()->json(TreeState::build($member));
    }
}
