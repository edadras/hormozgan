<?php

namespace App\Museum\Jobs;

use App\Models\Museum\RawDocument;
use App\Models\Museum\TextChunk;
use App\Museum\Pipeline\Chunker;
use App\Museum\Pipeline\TextExtractor;

/** Raw → text (with OCR fallback) → page-aware chunks → embeddings + AI extraction. */
class ProcessRawDocumentJob extends MuseumJob
{
    public int $timeout = 1800;

    public function __construct(public int $rawDocumentId, public bool $extract = true)
    {
        $this->onMuseumQueue('extract');
    }

    public function handle(TextExtractor $extractor, Chunker $chunker): void
    {
        $raw = RawDocument::findOrFail($this->rawDocumentId);
        if (! $raw->text_path) {
            $raw = $extractor->extract($raw);
        }
        if ($raw->status === 'failed' || ! $raw->text_path) {
            return;
        }
        $chunker->store($raw, (string) $raw->text(), ['source_id' => $raw->source_id, 'is_public' => false]);
        $raw->forceFill(['status' => 'chunked'])->save();
        EmbedChunksJob::dispatch($raw->getMorphClass(), $raw->id);
        if ($this->extract) {
            $chunkIds = TextChunk::where('chunkable_type', $raw->getMorphClass())->where('chunkable_id', $raw->id)->pluck('id');
            foreach ($chunkIds as $chunkId) {
                ExtractChunkJob::dispatch($chunkId);
            }
        }
    }
}
