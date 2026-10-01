<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantVariety extends MuseumModel
{
    protected $table = 'museum_plant_varieties';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id', 'entity_id');
    }
}
