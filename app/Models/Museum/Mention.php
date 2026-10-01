<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Mention extends MuseumModel
{
    protected $table = 'museum_mentions';

    protected $casts = [
        'confidence_score' => 'float',
    ];

    public function mentionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
