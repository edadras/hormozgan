<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends MuseumModel
{
    protected $table = 'museum_documents';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $guarded = [];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function rawDocument(): BelongsTo
    {
        return $this->belongsTo(RawDocument::class, 'raw_document_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class, 'document_id');
    }
}
