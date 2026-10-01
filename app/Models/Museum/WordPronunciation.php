<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordPronunciation extends MuseumModel
{
    protected $table = 'museum_word_pronunciations';

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class, 'word_id', 'entity_id');
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(AudioRecording::class, 'audio_media_id', 'media_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class, 'speaker_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }
}
