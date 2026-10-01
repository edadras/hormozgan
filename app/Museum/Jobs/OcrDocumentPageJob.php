<?php

namespace App\Museum\Jobs;

use App\Models\Museum\DocumentPage;
use App\Museum\Services\DocumentOcrService;

class OcrDocumentPageJob extends MuseumJob
{
    public int $timeout = 900;

    public function __construct(public int $pageId)
    {
        $this->onMuseumQueue('extract');
    }

    public function handle(DocumentOcrService $ocr): void
    {
        $page = DocumentPage::find($this->pageId);
        if (! $page) {
            return;
        }
        $ocr->ocrPage($page);
        $ocr->cleanup($page->fresh());
        $ocr->index($page->fresh());
    }
}
