<?php

namespace App\Models\Museum\Concerns;

use App\Models\Museum\Media;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasMedia
{
    public function media(): MorphToMany
    {
        return $this->morphToMany(Media::class, 'mediable', 'museum_mediables')
            ->withPivot(['role', 'sort'])->orderByPivot('sort');
    }
}
