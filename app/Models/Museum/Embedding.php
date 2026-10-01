<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Embedding extends MuseumModel
{
    protected $table = 'museum_embeddings';

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(TextChunk::class, 'text_chunk_id');
    }
}
