<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateCandidate extends MuseumModel
{
    protected $table = 'museum_duplicate_candidates';

    protected $casts = [
        'evidence' => 'array',
        'score' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function entityA(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_a_id');
    }

    public function entityB(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_b_id');
    }
}
