<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tree_id', 'person_id', 'path', 'created_by'])]
class PersonPhoto extends Model
{
}
