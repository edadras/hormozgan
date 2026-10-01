<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SearchDocument extends MuseumModel
{
    protected $table = 'museum_search_documents';

    protected $casts = [
        'is_public' => 'boolean',
        'boost' => 'float',
    ];

    public function searchable(): MorphTo
    {
        return $this->morphTo();
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }
}
