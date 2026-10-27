<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\SpouseLink;
use App\Models\Tree;
use App\Models\TreeMember;
use App\Services\TreeState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonController extends Controller
{
    /** POST /api/persons — bazadagi odamga nisbatan yangi qarindosh qo'shish. */
    public function store(Request $request)
    {
        [$user, $member] = $this->requireMember($request);
        if (! $member) {
            return response()->json(['message' => 'Avval daraxt yarating yoki daraxtga qo\'shiling'], 422);
        }

        $data = $request->validate([
            'role' => 'required|in:father,mother,spouse,child,sibling',
            'base_id' => 'required|string',
            'other_parent_id' => 'nullable|string',
            'name' => 'required|string|max:60',
            'g' => 'required|in:m,f',
            'by' => 'nullable|integer|min:1800|max:'.date('Y'),
            'dy' => 'nullable|integer|min:1800|max:'.date('Y'),
            'alive' => 'boolean',
        ]);

        $base = $this->findPerson($member->tree_id, $data['base_id']);
        if (! $base) {
            return response()->json(['message' => 'Tayanch karta topilmadi'], 404);
        }

        $role = $data['role'];
        if ($role === 'father' && $base->father_id) {
            return response()->json(['message' => 'Bu kishining otasi allaqachon kiritilgan'], 422);
        }
        if ($role === 'mother' && $base->mother_id) {
            return response()->json(['message' => 'Bu kishining onasi allaqachon kiritilgan'], 422);
        }
        if ($role === 'sibling' && ! $base->father_id && ! $base->mother_id) {
            return response()->json(['message' => 'Avval ota yoki onasini qo\'shing'], 422);
        }

        $otherParent = null;
        if ($role === 'child' && ! empty($data['other_parent_id'])) {
            $otherParent = $this->findPerson($member->tree_id, $data['other_parent_id']);
            if (! $otherParent) {
                return response()->json(['message' => 'Ikkinchi ota-ona topilmadi'], 404);
            }
        }

        DB::transaction(function () use ($user, $member, $base, $role, $data, $otherParent) {
            $gender = match ($role) {
                'father' => 'm',
                'mother' => 'f',
                default => $data['g'],
            };
            $alive = array_key_exists('alive', $data) ? (bool) $data['alive'] : true;
            $person = Person::create([
                'tree_id' => $member->tree_id,
                'name' => $data['name'],
                'gender' => $gender,
                'birth_year' => $data['by'] ?? null,
                'death_year' => $alive ? null : ($data['dy'] ?? null),
                'alive' => $alive,
                'created_by' => $user->id,
            ]);

            switch ($role) {
                case 'father':
                    $base->update(['father_id' => $person->id]);
                    if ($base->mother_id) {
                        SpouseLink::connect($member->tree_id, $person->id, $base->mother_id);
                    }
                    break;
                case 'mother':
                    $base->update(['mother_id' => $person->id]);
                    if ($base->father_id) {
                        SpouseLink::connect($member->tree_id, $person->id, $base->father_id);
                    }
                    break;
                case 'spouse':
                    SpouseLink::connect($member->tree_id, $base->id, $person->id);
                    break;
                case 'child':
                    $person->update($base->gender === 'm'
                        ? ['father_id' => $base->id, 'mother_id' => $otherParent?->id]
                        : ['mother_id' => $base->id, 'father_id' => $otherParent?->id]);
                    break;
                case 'sibling':
                    $person->update(['father_id' => $base->father_id, 'mother_id' => $base->mother_id]);
                    break;
            }
        });

        return response()->json(TreeState::build($member->refresh()), 201);
    }

    /** PATCH /api/persons/{id} — tahrirlash. */
    public function update(Request $request, string $id)
    {
        [, $member] = $this->requireMember($request);
        $person = $member ? $this->findPerson($member->tree_id, $id) : null;
        if (! $person) {
            return response()->json(['message' => 'Karta topilmadi'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:60',
            'g' => 'required|in:m,f',
            'by' => 'nullable|integer|min:1800|max:'.date('Y'),
            'dy' => 'nullable|integer|min:1800|max:'.date('Y'),
            'alive' => 'boolean',
        ]);

        $alive = array_key_exists('alive', $data) ? (bool) $data['alive'] : true;
        $person->update([
            'name' => $data['name'],
            'gender' => $data['g'],
            'birth_year' => $data['by'] ?? null,
            'death_year' => $alive ? null : ($data['dy'] ?? null),
            'alive' => $alive,
        ]);

        return response()->json(TreeState::build($member));
    }

    /** DELETE /api/persons/{id} — himoyalar bilan o'chirish. */
    public function destroy(Request $request, string $id)
    {
        [, $member] = $this->requireMember($request);
        $person = $member ? $this->findPerson($member->tree_id, $id) : null;
        if (! $person) {
            return response()->json(['message' => 'Karta topilmadi'], 404);
        }

        if (TreeMember::where('tree_id', $member->tree_id)->where('person_id', $person->id)->exists()) {
            return response()->json(['message' => 'Bu karta foydalanuvchiga biriktirilgan — o\'chirib bo\'lmaydi'], 422);
        }
        if (! $this->safeToDelete($member->tree_id, $person->id)) {
            return response()->json(['message' => 'Avval bu kishi orqali bog\'langan qarindoshlarni o\'chiring'], 422);
        }

        $person->delete(); // FK: farzandlarda ota/ona NULL bo'ladi, er-xotin bog'lari o'chadi

        return response()->json(TreeState::build($member));
    }

    /** POST /api/persons/{id}/claim — "Bu — men". */
    public function claim(Request $request, string $id)
    {
        [, $member] = $this->requireMember($request);
        $person = $member ? $this->findPerson($member->tree_id, $id) : null;
        if (! $person) {
            return response()->json(['message' => 'Karta topilmadi'], 404);
        }

        $takenBy = TreeMember::where('tree_id', $member->tree_id)
            ->where('person_id', $person->id)
            ->where('id', '!=', $member->id)
            ->exists();
        if ($takenBy) {
            return response()->json(['message' => 'Bu kartani boshqa foydalanuvchi egallagan'], 422);
        }

        $member->update(['person_id' => $person->id]);

        return response()->json(TreeState::build($member));
    }

    /* ---------- yordamchilar ---------- */

    private function requireMember(Request $request): array
    {
        $user = $request->attributes->get('tgUser');
        $member = TreeMember::where('user_id', $user->id)->first();

        return [$user, $member];
    }

    private function findPerson(int $treeId, string $id): ?Person
    {
        $pid = (int) ltrim($id, 'p');

        return $pid ? Person::where('tree_id', $treeId)->where('id', $pid)->first() : null;
    }

    /**
     * Frontend bilan bir xil qoidа: o'chirilganda daraxt uzilib qolmasligi kerak.
     * Ildizdan BFS — shu odam olib tashlanganda hamma yetib boladimi?
     */
    private function safeToDelete(int $treeId, int $personId): bool
    {
        $tree = Tree::find($treeId);
        $rootId = $tree?->root_person_id;
        if (! $rootId || $rootId === $personId) {
            return false;
        }

        $persons = Person::where('tree_id', $treeId)->get(['id', 'father_id', 'mother_id']);
        $adj = [];
        foreach ($persons as $p) {
            foreach ([$p->father_id, $p->mother_id] as $parent) {
                if ($parent) {
                    $adj[$p->id][] = $parent;
                    $adj[$parent][] = $p->id;
                }
            }
        }
        foreach (SpouseLink::where('tree_id', $treeId)->get() as $l) {
            $adj[$l->a_id][] = $l->b_id;
            $adj[$l->b_id][] = $l->a_id;
        }

        $seen = [$rootId => true];
        $queue = [$rootId];
        while ($queue) {
            $cur = array_shift($queue);
            foreach ($adj[$cur] ?? [] as $next) {
                if ($next !== $personId && ! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return count($seen) === $persons->count() - 1;
    }
}
