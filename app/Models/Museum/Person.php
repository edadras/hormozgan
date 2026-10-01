<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Person extends MuseumModel
{
    protected $table = 'museum_people';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'is_living' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
