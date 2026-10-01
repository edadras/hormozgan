<?php

namespace App\Museum\Search\Embeddings;

use Illuminate\Support\Facades\Http;

/** Neural embeddings over HTTP: Voyage AI (`/embeddings`) or any OpenAI-compatible server. */
class HttpEmbeddingProvider extends EmbeddingProvider
{
    public function __construct(private array $cfg, private string $flavor) {}

    public function model(): string
    {
        return $this->cfg['model'];
    }

    public function embed(array $texts, string $inputType = 'document'): array
    {
        $out = [];
        foreach (array_chunk($texts, (int) ($this->cfg['batch_size'] ?? 64)) as $batch) {
            $body = ['input' => array_values($batch), 'model' => $this->cfg['model']];
            if ($this->flavor === 'voyage') {
                $body['input_type'] = $inputType === 'query' ? 'query' : 'document';
            }
            $res = Http::withToken((string) $this->cfg['api_key'])->timeout(120)->retry(3, 2000)
                ->post(rtrim($this->cfg['base_url'], '/').'/embeddings', $body)->throw()->json();
            foreach (collect($res['data'] ?? [])->sortBy('index') as $row) {
                $out[] = self::normalize(array_map('floatval', $row['embedding']));
            }
        }

        return $out;
    }
}
