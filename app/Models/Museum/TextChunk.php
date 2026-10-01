<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TextChunk extends MuseumModel
{
    protected $table = 'museum_text_chunks';

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function chunkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function embedding(): HasOne
    {
        return $this->hasOne(Embedding::class, 'text_chunk_id');
    }
}
