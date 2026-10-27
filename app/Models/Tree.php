<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['invite_code', 'owner_id', 'root_person_id'])]
class Tree extends Model
{
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function persons()
    {
        return $this->hasMany(Person::class);
    }

    public function members()
    {
        return $this->hasMany(TreeMember::class);
    }

    public static function freshInviteCode(): string
    {
        do {
            // O'qishga qulay, adashtiradigan belgilarsiz (0/O, 1/I yo'q)
            $code = strtoupper(Str::random(8));
            $code = strtr($code, ['0' => '2', 'O' => '3', '1' => '4', 'I' => '5', 'L' => '6']);
        } while (self::where('invite_code', $code)->exists());

        return $code;
    }
}
