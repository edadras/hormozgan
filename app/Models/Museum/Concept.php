<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Concept extends MuseumModel
{
    protected $table = 'museum_concepts';

    public function words(): HasMany
    {
        return $this->hasMany(Word::class, 'concept_id');
    }
}
