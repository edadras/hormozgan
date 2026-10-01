<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ExtractionJob extends MuseumModel
{
    use HasUuid;

    protected $table = 'museum_extraction_jobs';

    protected $casts = [
        'stats' => 'array',
    ];

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function aiJob(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ExtractionCandidate::class, 'extraction_job_id');
    }
}
