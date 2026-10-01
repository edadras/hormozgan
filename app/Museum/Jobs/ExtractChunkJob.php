<?php

namespace App\Museum\Jobs;

use App\Models\Museum\TextChunk;
use App\Museum\Ai\LlmClient;
use App\Museum\Ai\LlmExtractor;
use App\Museum\Services\CandidateProcessor;

class ExtractChunkJob extends MuseumJob
{
    public int $timeout = 600;

    public function __construct(public int $chunkId, public ?int $importBatchId = null)
    {
        $this->onMuseumQueue('ai');
    }

    public function handle(LlmClient $llm, LlmExtractor $extractor, CandidateProcessor $processor): void
    {
        if (! $llm->available()) {
            return; // AI disabled: chunks stay available for later extraction and for keyword search.
        }
        $chunk = TextChunk::find($this->chunkId);
        if (! $chunk) {
            return;
        }
        $extractor->extractChunk($chunk, $this->importBatchId);
        $processor->processPending(200);
    }
}
