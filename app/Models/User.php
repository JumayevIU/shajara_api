<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tg_id', 'first_name', 'username', 'photo_url'])]
class User extends Model
{
    public function member()
    {
        return $this->hasOne(TreeMember::class);
    }
}
