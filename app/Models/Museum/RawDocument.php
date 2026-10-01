<?php

namespace App\Models\Museum;

use App\Models\Museum\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Raw acquired bytes. Preservation rule: raw documents are never deleted,
 * neither the row nor the stored file.
 */
class RawDocument extends MuseumModel
{
    use HasUuid;

    protected $table = 'museum_raw_documents';

    protected $casts = ['metadata' => 'array', 'fetched_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Raw documents are preserved permanently and cannot be deleted.');
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function crawlerSource(): BelongsTo
    {
        return $this->belongsTo(CrawlerSource::class);
    }

    public function extracts(): HasMany
    {
        return $this->hasMany(SourceExtract::class);
    }

    public function contents(): string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    public function text(): ?string
    {
        return $this->text_path ? Storage::disk($this->disk)->get($this->text_path) : null;
    }
}
