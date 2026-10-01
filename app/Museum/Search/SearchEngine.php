<?php

namespace App\Museum\Search;

use App\Models\Museum\SearchDocument;
use App\Museum\Search\Engines\DatabaseSearchEngine;
use App\Museum\Search\Engines\MysqlFulltextSearchEngine;
use App\Museum\Search\Engines\OpenSearchEngine;

/**
 * Keyword search over museum_search_documents. The table is always the source of truth;
 * external engines (OpenSearch) mirror it and are rebuildable with `museum:search:reindex`.
 */
abstract class SearchEngine
{
    public static function make(string $driver): self
    {
        return match ($driver) {
            'mysql_fulltext' => new MysqlFulltextSearchEngine,
            'opensearch' => new OpenSearchEngine(config('museum.search')),
            default => new DatabaseSearchEngine,
        };
    }

    /** Called after a SearchDocument row is written. */
    public function index(SearchDocument $doc): void {}

    public function remove(SearchDocument $doc): void {}

    /**
     * @param  array{types?: string[], place_id?: int|null, public_only?: bool, year_from?: int|null, year_to?: int|null}  $filters
     * @return array{total: int, hits: list<array{doc: SearchDocument, score: float}>}
     */
    abstract public function search(string $query, array $filters = [], int $page = 1, int $perPage = 20): array;
}
