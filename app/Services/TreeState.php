<?php

namespace App\Services;

use App\Models\Person;
use App\Models\SpouseLink;
use App\Models\Tree;
use App\Models\TreeMember;
use App\Models\User;

/**
 * Frontend kutadigan to'liq holat (state) javobini quradi.
 * Har bir yozuvdan keyin frontend shu to'liq holatni qayta oladi —
 * mijoz sodda qoladi, sinxronlashuv muammosi bo'lmaydi.
 */
class TreeState
{
    public static function forUser(User $user): array
    {
        $member = TreeMember::where('user_id', $user->id)->first();
        if (! $member) {
            return ['in_tree' => false];
        }

        return self::build($member);
    }

    public static function build(TreeMember $member): array
    {
        $tree = Tree::findOrFail($member->tree_id);
        $persons = Person::where('tree_id', $tree->id)->orderBy('id')->get();
        $links = SpouseLink::where('tree_id', $tree->id)->get();
        $claimed = TreeMember::where('tree_id', $tree->id)
            ->whereNotNull('person_id')->pluck('person_id')->all();

        $spouses = [];
        foreach ($links as $l) {
            $spouses[$l->a_id][] = 'p'.$l->b_id;
            $spouses[$l->b_id][] = 'p'.$l->a_id;
        }

        $map = [];
        foreach ($persons as $p) {
            $map['p'.$p->id] = [
                'id' => 'p'.$p->id,
                'name' => $p->name,
                'g' => $p->gender,
                'by' => $p->birth_year,
                'dy' => $p->death_year,
                'alive' => $p->alive,
                'fatherId' => $p->father_id ? 'p'.$p->father_id : null,
                'motherId' => $p->mother_id ? 'p'.$p->mother_id : null,
                'spouseIds' => $spouses[$p->id] ?? [],
                'claimed' => in_array($p->id, $claimed),
            ];
        }

        $rootId = $tree->root_person_id && isset($map['p'.$tree->root_person_id])
            ? 'p'.$tree->root_person_id
            : (count($map) ? array_key_first($map) : null);

        $inviteUrl = null;
        if ($bot = config('services.telegram.bot_username')) {
            $inviteUrl = 'https://t.me/'.$bot.'/'.config('services.telegram.app_short_name', 'shajara')
                .'?startapp='.$tree->invite_code;
        }

        $isOwner = $tree->owner_id === $member->user_id;
        $out = [
            'in_tree' => true,
            'state' => [
                'persons' => $map ?: new \stdClass,
                'root_id' => $rootId,
                'my_person_id' => $member->person_id ? 'p'.$member->person_id : null,
            ],
            'invite_url' => $inviteUrl,
            'invite_code' => $tree->invite_code,
            'is_owner' => $isOwner,
        ];

        // Egaga a'zolar ro'yxati — chiqarib yuborish uchun
        if ($isOwner) {
            $all = TreeMember::where('tree_id', $tree->id)->get();
            $users = User::whereIn('id', $all->pluck('user_id'))->get()->keyBy('id');
            $out['members'] = $all->map(fn ($m) => [
                'id' => $m->id,
                'name' => $users[$m->user_id]->first_name ?? '—',
                'person_id' => $m->person_id ? 'p'.$m->person_id : null,
                'self' => $m->user_id === $member->user_id,
            ])->values();
        }

        return $out;
    }
}
