<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactSource extends MuseumModel
{
    protected $table = 'museum_fact_sources';

    protected $casts = [
        'confidence_score' => 'float',
    ];

    public function fact(): BelongsTo
    {
        return $this->belongsTo(Fact::class, 'fact_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function extract(): BelongsTo
    {
        return $this->belongsTo(SourceExtract::class, 'source_extract_id');
    }
}
