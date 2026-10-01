<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlerSource extends MuseumModel
{
    protected $table = 'museum_crawler_sources';

    protected $casts = [
        'seed_urls' => 'array',
        'allowed_patterns' => 'array',
        'config' => 'array',
        'terms_reviewed' => 'boolean',
        'robots_allowed' => 'boolean',
        'enabled' => 'boolean',
        'robots_checked_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(CrawlerJob::class, 'crawler_source_id');
    }

    public function rawDocuments(): HasMany
    {
        return $this->hasMany(RawDocument::class, 'crawler_source_id');
    }
}
