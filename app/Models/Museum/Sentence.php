<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasCitations;
use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sentence extends MuseumModel
{
    use HasCitations, HasUuid, SoftDeletes;

    protected $table = 'museum_sentences';

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'dialect_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class, 'speaker_id');
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(AudioRecording::class, 'audio_media_id', 'media_id');
    }
}
