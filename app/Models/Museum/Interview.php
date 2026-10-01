<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Interview extends MuseumModel
{
    protected $table = 'museum_interviews';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'topics' => 'array',
        'recorded_on' => 'date',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
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

    public function audio(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'audio_media_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'video_media_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(InterviewSegment::class, 'interview_id');
    }
}
