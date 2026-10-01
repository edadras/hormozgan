<?php

namespace App\Museum\Search\Engines;

use App\Models\Museum\Place;
use App\Models\Museum\SearchDocument;
use App\Museum\Search\SearchEngine;
use App\Museum\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Portable engine (SQLite/MySQL/Postgres) on normalized columns. Candidate retrieval with
 * LIKE on every token (AND), plus compact-key matching ("بندر عباس" finds "بندرعباس"),
 * then ranking in PHP. Suitable up to a few hundred thousand documents; switch the driver
 * to mysql_fulltext or opensearch beyond that.
 */
class DatabaseSearchEngine extends SearchEngine
{
    public function search(string $query, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $norm = TextNormalizer::normalize($query);
        $compact = TextNormalizer::compact($query);
        if ($norm === '') {
            return ['total' => 0, 'hits' => []];
        }
        $tokens = array_slice(TextNormalizer::tokens($query), 0, 8);

        $q = SearchDocument::query();
        $this->applyFilters($q, $filters);
        $q->where(function (Builder $w) use ($tokens, $compact) {
            $w->where(function (Builder $all) use ($tokens) {
                foreach ($tokens as $t) {
                    $all->where('normalized_text', 'like', '%'.$t.'%');
                }
            });
            if (mb_strlen($compact) >= 3) {
                $w->orWhere('compact_text', 'like', '%'.$compact.'%');
            }
        });

        $candidates = $q->limit(2000)->get();
        $scored = $candidates->map(fn (SearchDocument $d) => ['doc' => $d, 'score' => $this->score($d, $norm, $compact, $tokens)])
            ->sortByDesc('score')->values();

        return [
            'total' => $scored->count(),
            'hits' => $scored->slice(($page - 1) * $perPage, $perPage)->values()->all(),
        ];
    }

    protected function applyFilters(Builder $q, array $filters): void
    {
        if ($filters['public_only'] ?? true) {
            $q->where('is_public', true);
        }
        if (! empty($filters['types'])) {
            $q->whereIn('doc_type', $filters['types']);
        }
        if (! empty($filters['place_id'])) {
            $path = Place::where('entity_id', $filters['place_id'])->value('path');
            $q->where(function (Builder $w) use ($filters, $path) {
                $w->where('place_id', $filters['place_id']);
                if ($path) {
                    $w->orWhereIn('place_id', Place::where('path', 'like', $path.'%')->select('entity_id'));
                }
            });
        }
        if (! empty($filters['year_from'])) {
            $q->where(fn ($w) => $w->whereNull('year_to')->orWhere('year_to', '>=', $filters['year_from']));
        }
        if (! empty($filters['year_to'])) {
            $q->where(fn ($w) => $w->whereNull('year_from')->orWhere('year_from', '<=', $filters['year_to']));
        }
    }

    protected function score(SearchDocument $d, string $norm, string $compact, array $tokens): float
    {
        $title = TextNormalizer::normalize($d->title);
        $aliases = ' '.($d->aliases ?? '').' ';
        $s = 0.0;
        if ($title === $norm) {
            $s += 10;
        } elseif (str_starts_with($title, $norm)) {
            $s += 6;
        } elseif (str_contains($title, $norm)) {
            $s += 4;
        }
        if (str_contains($aliases, ' '.$norm.' ') || str_contains(' '.($d->compact_text ?? '').' ', ' '.$compact.' ')) {
            $s += 7;
        } elseif (str_contains($d->compact_text ?? '', $compact)) {
            $s += 3;
        }
        foreach ($tokens as $t) {
            $s += substr_count($d->normalized_text, $t) > 0 ? 1 : 0;
        }

        return $s * (float) $d->boost;
    }
}
