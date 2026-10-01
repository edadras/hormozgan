<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Diagram extends MuseumModel
{
    protected $table = 'museum_diagrams';

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function hotspots(): HasMany
    {
        return $this->hasMany(DiagramHotspot::class, 'diagram_id');
    }
}
