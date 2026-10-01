<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use App\Museum\Enums\License;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Source extends MuseumModel
{
    use HasUuid, SoftDeletes;

    protected $table = 'museum_sources';

    protected $casts = [
        'retrieved_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'topics' => 'array',
        'regions' => 'array',
        'metadata' => 'array',
    ];

    public const TYPES = [
        'book', 'article', 'thesis', 'encyclopedia', 'gazetteer', 'government_dataset', 'census',
        'open_dataset', 'archive_document', 'map', 'newspaper', 'photo_archive', 'website',
        'interview', 'field_recording', 'community_submission',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::saving(function (self $s) {
            $s->url_hash = $s->url ? hash('sha256', static::canonicalUrl($s->url)) : null;
        });
    }

    public static function canonicalUrl(string $url): string
    {
        $url = trim($url);
        // Drop tracking parameters such as utm_* so the same source is not registered twice.
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return $url;
        }
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
            $query = array_filter($query, fn ($k) => ! str_starts_with((string) $k, 'utm_'), ARRAY_FILTER_USE_KEY);
            ksort($query);
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $path = $parts['path'] ?? '/';

        return $scheme.'://'.strtolower($parts['host']).rtrim($path, '/').($query ? '?'.http_build_query($query) : '');
    }

    public function extracts(): HasMany
    {
        return $this->hasMany(SourceExtract::class);
    }

    public function factSources(): HasMany
    {
        return $this->hasMany(FactSource::class);
    }

    public function crawlerSources(): HasMany
    {
        return $this->hasMany(CrawlerSource::class);
    }

    public function rawDocuments(): HasMany
    {
        return $this->hasMany(RawDocument::class);
    }

    public function licenseEnum(): License
    {
        return License::tryFrom($this->license) ?? License::Unknown;
    }

    /** Compact label for repeated inline citations (full citation is on the source page). */
    public function shortLabel(): string
    {
        return $this->metadata['short_label'] ?? Str::limit($this->title, 48);
    }

    /** Short human citation, e.g. "Lorimer (1908). Gazetteer of the Persian Gulf." */
    public function citationLabel(): string
    {
        $parts = [];
        if ($this->author) {
            $parts[] = $this->author;
        }
        if ($this->publication_date) {
            $parts[] = '('.$this->publication_date.')';
        }
        $label = trim(implode(' ', $parts));

        return ($label ? $label.'. ' : '').$this->title.($this->publisher ? '. '.$this->publisher : '');
    }
}
