<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasCitations;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Proverb extends MuseumModel
{
    use HasCitations;

    protected $table = 'museum_proverbs';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function dialect(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'dialect_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'place_id');
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(AudioRecording::class, 'audio_media_id', 'media_id');
    }
}
