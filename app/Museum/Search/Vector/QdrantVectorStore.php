<?php

namespace App\Museum\Search\Vector;

use App\Models\Museum\TextChunk;
use Illuminate\Support\Facades\Http;

/** Qdrant vector database for large corpora (point id = text chunk id; payload: model, is_public). */
class QdrantVectorStore extends VectorStore
{
    public function __construct(private array $cfg) {}

    private function http()
    {
        $h = Http::baseUrl(rtrim($this->cfg['qdrant_url'], '/'))->acceptJson()->timeout(60);

        return $this->cfg['qdrant_api_key'] ? $h->withHeaders(['api-key' => $this->cfg['qdrant_api_key']]) : $h;
    }

    private function collection(string $model): string
    {
        return $this->cfg['qdrant_collection'].'_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($model));
    }

    public function upsert(string $model, array $vectors): void
    {
        if (! $vectors) {
            return;
        }
        $dims = count(reset($vectors));
        $col = $this->collection($model);
        $this->http()->put("/collections/{$col}", ['vectors' => ['size' => $dims, 'distance' => 'Cosine']]);
        $public = TextChunk::whereIn('id', array_keys($vectors))->pluck('is_public', 'id');
        $points = [];
        foreach ($vectors as $id => $v) {
            $points[] = ['id' => $id, 'vector' => $v, 'payload' => ['is_public' => (bool) ($public[$id] ?? false)]];
        }
        $this->http()->put("/collections/{$col}/points?wait=true", ['points' => $points])->throw();
    }

    public function search(string $model, array $query, int $k, bool $publicOnly = true): array
    {
        $body = ['vector' => $query, 'limit' => $k, 'with_payload' => false];
        if ($publicOnly) {
            $body['filter'] = ['must' => [['key' => 'is_public', 'match' => ['value' => true]]]];
        }
        $res = $this->http()->post('/collections/'.$this->collection($model).'/points/search', $body)->throw()->json('result') ?? [];

        return collect($res)->mapWithKeys(fn ($r) => [(int) $r['id'] => (float) $r['score']])->all();
    }
}
