<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalMap extends MuseumModel
{
    use HasUuid;

    protected $table = 'museum_maps';

    protected $casts = [
        'is_georeferenced' => 'boolean',
        'north' => 'float',
        'south' => 'float',
        'east' => 'float',
        'west' => 'float',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id', 'entity_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }
}
