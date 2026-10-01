<?php

namespace App\Museum\Search\Vector;

use App\Models\Museum\Embedding;
use App\Museum\Search\Embeddings\EmbeddingProvider;
use Illuminate\Support\Facades\DB;

/**
 * Vectors in museum_embeddings (packed float32). Exact cosine search by streaming scan,
 * bounded by `database_scan_limit`. Fine up to ~200k chunks; use Qdrant beyond that.
 */
class DatabaseVectorStore extends VectorStore
{
    public function __construct(private array $cfg) {}

    public function upsert(string $model, array $vectors): void
    {
        foreach ($vectors as $chunkId => $v) {
            Embedding::updateOrCreate(
                ['text_chunk_id' => $chunkId, 'model' => $model],
                ['dimensions' => count($v), 'vector' => EmbeddingProvider::pack($v)]
            );
        }
    }

    public function search(string $model, array $query, int $k, bool $publicOnly = true): array
    {
        $best = [];
        $limit = (int) ($this->cfg['database_scan_limit'] ?? 200000);
        $q = DB::table('museum_embeddings as e')->join('museum_text_chunks as c', 'c.id', '=', 'e.text_chunk_id')
            ->where('e.model', $model)->select('e.id', 'e.text_chunk_id', 'e.vector')->orderBy('e.id')->limit($limit);
        if ($publicOnly) {
            $q->where('c.is_public', true);
        }
        foreach ($q->cursor() as $row) {
            $v = EmbeddingProvider::unpack($row->vector);
            $dot = 0.0;
            foreach ($query as $i => $x) {
                $dot += $x * ($v[$i] ?? 0.0);
            }
            if (count($best) < $k || $dot > min($best)) {
                $best[$row->text_chunk_id] = $dot;
                if (count($best) > $k) {
                    asort($best);
                    array_shift($best);
                }
            }
        }
        arsort($best);

        return $best;
    }
}
