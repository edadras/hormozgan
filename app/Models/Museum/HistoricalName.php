<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasCitations;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HistoricalName extends MuseumModel
{
    use HasCitations, SoftDeletes;

    protected $table = 'museum_historical_names';

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
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
