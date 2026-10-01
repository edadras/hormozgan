<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends MuseumModel
{
    protected $table = 'museum_locations';

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function map(): BelongsTo
    {
        return $this->belongsTo(HistoricalMap::class, 'map_id');
    }
}
