<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAcervo;
use Illuminate\Database\Eloquent\Model;

/** Par de peças marcado como "não são iguais" no detector de duplicatas. */
class DupIgnore extends Model
{
    use BelongsToAcervo;

    protected $guarded = ['id'];
}
