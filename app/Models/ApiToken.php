<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'user_id', 'token_hash', 'token_plain', 'device', 'claimed_at'])]
class ApiToken extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
