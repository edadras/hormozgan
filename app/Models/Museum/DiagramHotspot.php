<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagramHotspot extends MuseumModel
{
    protected $table = 'museum_diagram_hotspots';

    public function diagram(): BelongsTo
    {
        return $this->belongsTo(Diagram::class, 'diagram_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'part_entity_id');
    }
}
