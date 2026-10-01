<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClothingItem extends MuseumModel
{
    protected $table = 'museum_clothing_items';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'is_historical' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'parent_item_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ClothingItem::class, 'parent_item_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
