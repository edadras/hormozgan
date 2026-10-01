<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FactConflict extends MuseumModel
{
    protected $table = 'museum_fact_conflicts';

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class, 'conflict_id');
    }

    public function preferredFact(): BelongsTo
    {
        return $this->belongsTo(Fact::class, 'preferred_fact_id');
    }
}
