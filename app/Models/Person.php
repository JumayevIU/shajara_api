<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tree_id', 'name', 'gender', 'birth_year', 'death_year', 'alive', 'father_id', 'mother_id', 'created_by'])]
class Person extends Model
{
    protected $table = 'persons';

    protected function casts(): array
    {
        return ['alive' => 'boolean'];
    }

    public function tree()
    {
        return $this->belongsTo(Tree::class);
    }
}
