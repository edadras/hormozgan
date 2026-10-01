<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends MuseumModel
{
    protected $table = 'museum_photos';

    protected $primaryKey = 'media_id';

    public $incrementing = false;

    protected $guarded = [];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function depictedPlace(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'depicted_place_id');
    }
}
