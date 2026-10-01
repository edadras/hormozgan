<?php

namespace App\Museum\Search\Engines;

use App\Models\Museum\SearchDocument;
use App\Museum\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8 FULLTEXT (ngram parser, created by the migration) for large tables. Falls back to
 * the portable engine on other drivers. Compact-key matches are merged in so that spacing
 * variants of names still match.
 */
class MysqlFulltextSearchEngine extends DatabaseSearchEngine
{
    public function search(string $query, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return parent::search($query, $filters, $page, $perPage);
        }
        $norm = TextNormalizer::normalize($query);
        $compact = TextNormalizer::compact($query);
        if ($norm === '') {
            return ['total' => 0, 'hits' => []];
        }
        $tokens = TextNormalizer::tokens($query);
        $boolean = implode(' ', array_map(fn ($t) => '+"'.str_replace('"', '', $t).'"', $tokens));

        $q = SearchDocument::query()->select('*')
            ->selectRaw('MATCH(normalized_text) AGAINST (? IN BOOLEAN MODE) AS ft_score', [$boolean]);
        $this->applyFilters($q, $filters);
        $q->where(function ($w) use ($boolean, $compact) {
            $w->whereRaw('MATCH(normalized_text) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
            if (mb_strlen($compact) >= 3) {
                $w->orWhere('compact_text', 'like', '%'.$compact.'%');
            }
        });
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('ft_score')->limit(500)->get();
        $scored = $rows->map(fn ($d) => ['doc' => $d, 'score' => $this->score($d, $norm, $compact, $tokens) + (float) $d->ft_score])
            ->sortByDesc('score')->values();

        return ['total' => $total, 'hits' => $scored->slice(($page - 1) * $perPage, $perPage)->values()->all()];
    }
}
