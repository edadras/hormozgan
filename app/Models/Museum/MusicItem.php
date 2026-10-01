<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusicItem extends MuseumModel
{
    protected $table = 'museum_music_items';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'lyrics_publishable' => 'boolean',
    ];

    protected $hidden = ['lyrics'];

    public function publicLyrics(): ?string
    {
        return $this->lyrics_publishable ? $this->lyrics : null;
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
