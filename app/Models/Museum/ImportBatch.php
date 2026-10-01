<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends MuseumModel
{
    use HasUuid;

    protected $table = 'museum_import_batches';

    protected $casts = [
        'options' => 'array',
        'error_log' => 'array',
        'report' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }
}
