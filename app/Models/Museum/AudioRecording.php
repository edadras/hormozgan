<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioRecording extends MuseumModel
{
    protected $table = 'museum_audio_recordings';

    protected $primaryKey = 'media_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'is_synthetic' => 'boolean',
        'recorded_on' => 'date',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class, 'speaker_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'dialect_id');
    }
}
