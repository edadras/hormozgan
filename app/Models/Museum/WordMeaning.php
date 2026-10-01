<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordMeaning extends MuseumModel
{
    protected $table = 'museum_word_meanings';

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'word_id', 'entity_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }
}
