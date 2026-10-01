<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Citation extends MuseumModel
{
    protected $table = 'museum_citations';

    public function citable(): MorphTo
    {
        return $this->morphTo();
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
