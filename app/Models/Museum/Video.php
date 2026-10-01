<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends MuseumModel
{
    protected $table = 'museum_videos';

    protected $primaryKey = 'media_id';

    public $incrementing = false;

    protected $guarded = [];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}
