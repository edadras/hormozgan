<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DialectArea extends MuseumModel
{
    protected $table = 'museum_dialect_areas';

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'dialect_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }
}
