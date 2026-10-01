<?php

namespace App\Museum\Search\Engines;

use App\Models\Museum\SearchDocument;
use App\Museum\Search\SearchEngine;
use App\Museum\Support\TextNormalizer;
use Illuminate\Support\Facades\Http;

/**
 * OpenSearch / Elasticsearch mirror of museum_search_documents for very large volumes.
 * Documents are indexed with pre-normalized text so query-time behaviour equals the
 * database engines.
 */
class OpenSearchEngine extends SearchEngine
{
    public function __construct(private array $cfg) {}

    private function http()
    {
        $h = Http::baseUrl(rtrim($this->cfg['opensearch_url'], '/'))->acceptJson()->timeout(30);

        return $this->cfg['opensearch_user'] ? $h->withBasicAuth($this->cfg['opensearch_user'], (string) $this->cfg['opensearch_password']) : $h;
    }

    private function index_(): string
    {
        return $this->cfg['opensearch_index'];
    }

    public function createIndex(): void
    {
        $this->http()->put('/'.$this->index_(), [
            'settings' => ['analysis' => ['analyzer' => ['museum' => ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase']]]]],
            'mappings' => ['properties' => [
                'title' => ['type' => 'text', 'analyzer' => 'museum'],
                'aliases' => ['type' => 'text', 'analyzer' => 'museum'],
                'normalized_text' => ['type' => 'text', 'analyzer' => 'museum'],
                'compact_text' => ['type' => 'keyword'],
                'doc_type' => ['type' => 'keyword'],
                'place_id' => ['type' => 'long'],
                'year_from' => ['type' => 'integer'],
                'year_to' => ['type' => 'integer'],
                'is_public' => ['type' => 'boolean'],
                'boost' => ['type' => 'float'],
            ]],
        ]);
    }

    public function index(SearchDocument $doc): void
    {
        $this->http()->put('/'.$this->index_().'/_doc/'.$doc->id, [
            'title' => TextNormalizer::normalize($doc->title), 'aliases' => $doc->aliases, 'normalized_text' => $doc->normalized_text,
            'compact_text' => $doc->compact_text, 'doc_type' => $doc->doc_type, 'place_id' => $doc->place_id,
            'year_from' => $doc->year_from, 'year_to' => $doc->year_to, 'is_public' => $doc->is_public, 'boost' => $doc->boost,
        ]);
    }

    public function remove(SearchDocument $doc): void
    {
        $this->http()->delete('/'.$this->index_().'/_doc/'.$doc->id);
    }

    public function search(string $query, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $norm = TextNormalizer::normalize($query);
        $filter = [];
        if ($filters['public_only'] ?? true) {
            $filter[] = ['term' => ['is_public' => true]];
        }
        if (! empty($filters['types'])) {
            $filter[] = ['terms' => ['doc_type' => $filters['types']]];
        }
        if (! empty($filters['place_id'])) {
            $filter[] = ['term' => ['place_id' => $filters['place_id']]];
        }
        $res = $this->http()->post('/'.$this->index_().'/_search', [
            'from' => ($page - 1) * $perPage, 'size' => $perPage,
            'query' => ['function_score' => [
                'query' => ['bool' => ['filter' => $filter, 'should' => [
                    ['match_phrase' => ['title' => ['query' => $norm, 'boost' => 10]]],
                    ['match' => ['aliases' => ['query' => $norm, 'boost' => 6]]],
                    ['term' => ['compact_text' => ['value' => TextNormalizer::compact($query), 'boost' => 8]]],
                    ['match' => ['normalized_text' => ['query' => $norm, 'operator' => 'and']]],
                ], 'minimum_should_match' => 1]],
                'field_value_factor' => ['field' => 'boost', 'missing' => 1],
            ]],
        ])->throw()->json();
        $ids = collect($res['hits']['hits'] ?? [])->mapWithKeys(fn ($h) => [(int) $h['_id'] => (float) $h['_score']]);
        $docs = SearchDocument::whereIn('id', $ids->keys())->get()->keyBy('id');

        return [
            'total' => (int) ($res['hits']['total']['value'] ?? 0),
            'hits' => $ids->map(fn ($score, $id) => isset($docs[$id]) ? ['doc' => $docs[$id], 'score' => $score] : null)->filter()->values()->all(),
        ];
    }
}
