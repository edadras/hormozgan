<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlerJob extends MuseumModel
{
    protected $table = 'museum_crawler_jobs';

    protected $casts = [
        'log' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function crawlerSource(): BelongsTo
    {
        return $this->belongsTo(CrawlerSource::class, 'crawler_source_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
