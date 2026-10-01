<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dialect extends MuseumModel
{
    protected $table = 'museum_dialects';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'parent_dialect_id');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(DialectArea::class, 'dialect_id');
    }
}
