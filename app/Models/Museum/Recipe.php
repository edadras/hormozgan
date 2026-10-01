<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasCitations;
use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipe extends MuseumModel
{
    use HasCitations, HasUuid, SoftDeletes;

    protected $table = 'museum_recipes';

    protected $casts = [
        'steps' => 'array',
    ];

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class, 'food_id', 'entity_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class, 'speaker_id');
    }
}
