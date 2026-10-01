<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceExtract extends MuseumModel
{
    protected $table = 'museum_source_extracts';

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function rawDocument(): BelongsTo
    {
        return $this->belongsTo(RawDocument::class, 'raw_document_id');
    }

    public function documentPage(): BelongsTo
    {
        return $this->belongsTo(DocumentPage::class, 'document_page_id');
    }
}
