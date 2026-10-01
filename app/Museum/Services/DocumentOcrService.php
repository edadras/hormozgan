<?php

namespace App\Museum\Services;

use App\Models\Museum\DocumentPage;
use App\Museum\Ai\LlmClient;
use App\Museum\Pipeline\Chunker;
use App\Museum\Pipeline\OcrEngine;
use Illuminate\Support\Facades\Storage;

/**
 * OCR workflow for archive documents (section 37):
 *   image/PDF page → OCR (raw text kept forever) → AI cleanup (separate field)
 *   → mention linking + chunks → human verification (corrected_text).
 */
class DocumentOcrService
{
    public function __construct(private OcrEngine $ocr, private LlmClient $llm, private MentionLinker $mentions, private Chunker $chunker) {}

    public function ocrPage(DocumentPage $page): DocumentPage
    {
        if ($page->ocr_text_raw !== null) {
            return $page; // never overwrite original OCR
        }
        $media = $page->image;
        if (! $media) {
            throw new \RuntimeException('Page has no image.');
        }
        $r = $this->ocr->recognize(Storage::disk($media->disk)->path($media->path));
        $page->forceFill(['ocr_text_raw' => $r['text'], 'ocr_engine' => $r['engine'], 'ocr_confidence' => $r['confidence'], 'status' => 'ocr_done'])->save();

        return $page;
    }

    /** AI cleanup fixes OCR artefacts only; it must not add, translate or "improve" content. */
    public function cleanup(DocumentPage $page): DocumentPage
    {
        if (! $page->ocr_text_raw || ! $this->llm->available()) {
            return $page;
        }
        $system = 'You correct OCR errors in historical documents about Hormozgan. Fix only character-level OCR '
            .'artefacts (broken letters, wrong Arabic/Persian letter forms, hyphenation, line-break noise). Do not add, '
            .'remove, translate, modernize or summarize content. Keep original spellings of names and dates. If unsure, '
            .'leave the text as it is. Return only the corrected text.';
        $res = $this->llm->text($system, $page->ocr_text_raw, ['job_type' => 'ocr_cleanup', 'subject' => $page, 'max_tokens' => 8000]);
        $page->forceFill(['cleaned_text' => trim((string) $res->text), 'status' => 'ai_cleaned'])->save();

        return $page;
    }

    public function verify(DocumentPage $page, string $correctedText, int $userId): DocumentPage
    {
        $page->forceFill(['corrected_text' => $correctedText, 'corrected_by' => $userId, 'corrected_at' => now(), 'status' => 'verified'])->save();
        $this->index($page);

        return $page;
    }

    public function index(DocumentPage $page): void
    {
        $text = $page->bestText();
        if (! $text) {
            return;
        }
        $this->mentions->link($page, $text);
        $doc = $page->document;
        $this->chunker->store($page, $text, [
            'source_id' => $doc?->source_id,
            'entity_id' => $doc?->entity_id,
            'page_number' => (string) $page->page_number,
            'is_public' => (bool) ($doc?->entity?->isPublished()),
        ]);
    }
}
