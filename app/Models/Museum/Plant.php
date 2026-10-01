<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plant extends MuseumModel
{
    protected $table = 'museum_plants';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'is_cultivated' => 'boolean',
        'is_native' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function varieties(): HasMany
    {
        return $this->hasMany(PlantVariety::class, 'plant_id');
    }
}
