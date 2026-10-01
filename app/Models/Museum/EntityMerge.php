<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityMerge extends MuseumModel
{
    protected $table = 'museum_entity_merges';

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function mergedEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'merged_entity_id');
    }

    public function intoEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'into_entity_id');
    }
}
