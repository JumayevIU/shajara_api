<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tree_id', 'a_id', 'b_id'])]
class SpouseLink extends Model
{
    public $timestamps = false;

    /** Juftlikni normallashtirib (a < b) saqlaydi, takrorini yaratmaydi. */
    public static function connect(int $treeId, int $x, int $y): void
    {
        [$a, $b] = $x < $y ? [$x, $y] : [$y, $x];
        self::firstOrCreate(['a_id' => $a, 'b_id' => $b], ['tree_id' => $treeId]);
    }
}
