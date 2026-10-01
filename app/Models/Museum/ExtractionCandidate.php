<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ExtractionCandidate extends MuseumModel
{
    protected $table = 'museum_extraction_candidates';

    protected $casts = [
        'payload' => 'array',
        'evidence_source_ids' => 'array',
        'confidence_score' => 'float',
        'match_score' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ExtractionJob::class, 'extraction_job_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function extract(): BelongsTo
    {
        return $this->belongsTo(SourceExtract::class, 'source_extract_id');
    }

    public function matchedEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'matched_entity_id');
    }

    public function result(): MorphTo
    {
        return $this->morphTo();
    }
}
