<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Craft extends MuseumModel
{
    protected $table = 'museum_crafts';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'unesco_listed' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
