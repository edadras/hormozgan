<?php

namespace App\Museum\Jobs;

use App\Models\Museum\TextChunk;
use App\Museum\Search\Embeddings\EmbeddingProvider;
use App\Museum\Search\Vector\VectorStore;

/** Embeds the chunks of one record (or all chunks lacking a vector when type is null). */
class EmbedChunksJob extends MuseumJob
{
    public int $timeout = 1800;

    public function __construct(public ?string $chunkableType = null, public ?int $chunkableId = null, public int $limit = 2000)
    {
        $this->onMuseumQueue('index');
    }

    public function handle(EmbeddingProvider $embeddings, VectorStore $store): void
    {
        $model = $embeddings->model();
        // Fetch-until-empty instead of chunk(): the "missing embedding" filter changes as we write,
        // so offset pagination would skip rows.
        $done = 0;
        while ($done < $this->limit) {
            $chunks = TextChunk::query()
                ->when($this->chunkableType, fn ($q) => $q->where('chunkable_type', $this->chunkableType)->where('chunkable_id', $this->chunkableId))
                ->whereNotExists(fn ($s) => $s->from('museum_embeddings')->whereColumn('museum_embeddings.text_chunk_id', 'museum_text_chunks.id')->where('model', $model))
                ->orderBy('id')->limit(64)->get(['id', 'text']);
            if ($chunks->isEmpty()) {
                break;
            }
            $vectors = $embeddings->embed($chunks->pluck('text')->all(), 'document');
            $store->upsert($model, array_combine($chunks->pluck('id')->all(), $vectors));
            $done += $chunks->count();
        }
    }
}
