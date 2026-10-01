<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodIngredient extends MuseumModel
{
    protected $table = 'museum_food_ingredients';

    protected $casts = [
        'is_optional' => 'boolean',
    ];

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class, 'food_id', 'entity_id');
    }

    public function ingredientEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'ingredient_entity_id');
    }
}
