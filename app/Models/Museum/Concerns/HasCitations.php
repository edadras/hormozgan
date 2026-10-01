<?php

namespace App\Models\Museum\Concerns;

use App\Models\Museum\Citation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCitations
{
    public function citations(): MorphMany
    {
        return $this->morphMany(Citation::class, 'citable');
    }
}
