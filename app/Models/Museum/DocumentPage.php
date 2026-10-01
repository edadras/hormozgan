<?php

namespace App\Models\Museum;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPage extends MuseumModel
{
    protected $table = 'museum_document_pages';

    protected $casts = [
        'corrected_at' => 'datetime',
        'ocr_confidence' => 'float',
    ];

    /** Best available text: human-corrected, then AI-cleaned, then raw OCR. */
    public function bestText(): ?string
    {
        return $this->corrected_text ?? $this->cleaned_text ?? $this->ocr_text_raw;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id', 'entity_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }
}
