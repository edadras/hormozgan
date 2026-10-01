<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordRelation extends MuseumModel
{
    protected $table = 'museum_word_relations';

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'word_id', 'entity_id');
    }

    public function relatedWord(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'related_word_id', 'entity_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }
}
