<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoPair extends MuseumModel
{
    protected $table = 'museum_photo_pairs';

    public function thenMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'then_media_id');
    }

    public function nowMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'now_media_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }
}
