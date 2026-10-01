<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Food extends MuseumModel
{
    protected $table = 'museum_foods';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'is_ceremonial' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(FoodIngredient::class, 'food_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'food_id');
    }
}
