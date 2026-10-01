<?php

namespace App\Museum\Services;

use App\Models\Museum\Entity;
use App\Models\Museum\EntityAlias;
use App\Models\Museum\EntityType;
use App\Models\Museum\HistoricalName;
use App\Models\Museum\Place;
use App\Museum\Support\Similarity;
use App\Museum\Support\TextNormalizer;
use Illuminate\Support\Collection;

/**
 * Entity resolution: maps a surface form ("Bandar-e Abbas", "بندر عباس", an old name)
 * to existing entities with a score and a decision.
 *
 *  score ≥ auto_match_threshold  → matched (safe to link automatically)
 *  score ≥ review_threshold      → review (needs a human)
 *  otherwise                     → new (discovery candidate)
 */
class EntityResolver
{
    public const MATCHED = 'matched';

    public const REVIEW = 'review';

    public const NEW = 'new';

    /**
     * @param  array{types?: string[], parent_place_id?: int|null, lat?: float|null, lng?: float|null, wikidata_id?: string|null}  $context
     * @return array{decision: string, entity: ?Entity, score: float, candidates: Collection}
     */
    public function resolve(string $surface, array $context = []): array
    {
        $candidates = $this->candidates($surface, $context);
        $best = $candidates->first();
        $score = $best['score'] ?? 0.0;
        $auto = (float) config('museum.resolution.auto_match_threshold', 0.92);
        $review = (float) config('museum.resolution.review_threshold', 0.75);

        // Ambiguity guard: two different strong candidates → never auto-match.
        $second = $candidates->skip(1)->first();
        $ambiguous = $second && ($score - $second['score']) < 0.03 && $score < 1.01;

        $decision = match (true) {
            $score >= $auto && ! $ambiguous => self::MATCHED,
            $score >= $review => self::REVIEW,
            default => self::NEW,
        };

        return [
            'decision' => $decision,
            'entity' => $decision === self::NEW ? null : ($best['entity'] ?? null),
            'score' => round($score, 4),
            'candidates' => $candidates,
        ];
    }

    /** @return Collection<int, array{entity: Entity, score: float, reasons: string[]}> */
    public function candidates(string $surface, array $context = [], int $limit = 10): Collection
    {
        $normalized = TextNormalizer::normalize($surface);
        $compact = TextNormalizer::compact($surface);
        if ($normalized === '') {
            return collect();
        }

        // External identifier is decisive.
        if (! empty($context['wikidata_id'])) {
            $byId = Entity::where('wikidata_id', $context['wikidata_id'])->first();
            if ($byId) {
                return collect([['entity' => $this->followMerges($byId), 'score' => 1.02, 'reasons' => ['external_id']]]);
            }
        }

        $typeIds = ! empty($context['types']) ? EntityType::idsFor($context['types']) : null;
        $scores = [];

        $add = function (int $entityId, float $score, string $reason) use (&$scores) {
            if (! isset($scores[$entityId]) || $scores[$entityId]['score'] < $score) {
                $scores[$entityId] = ['score' => $score, 'reasons' => [$reason]];
            } elseif (! in_array($reason, $scores[$entityId]['reasons'], true)) {
                $scores[$entityId]['reasons'][] = $reason;
            }
        };

        foreach (EntityAlias::where('normalized_alias', $normalized)->limit(50)->get(['entity_id', 'alias_type']) as $a) {
            $add($a->entity_id, $a->alias_type === 'historical' ? 0.9 : 1.0, 'alias_exact');
        }
        foreach (EntityAlias::where('compact_key', $compact)->limit(50)->get(['entity_id', 'alias_type']) as $a) {
            $add($a->entity_id, $a->alias_type === 'historical' ? 0.88 : 0.97, 'alias_compact');
        }
        foreach (HistoricalName::where('normalized_name', $normalized)->limit(50)->pluck('entity_id') as $id) {
            $add($id, 0.9, 'historical_name');
        }

        // Fuzzy: aliases sharing a prefix; bounded to keep this O(index scan).
        if (mb_strlen($compact) >= 3) {
            $prefix = mb_substr($normalized, 0, 2);
            EntityAlias::where('normalized_alias', 'like', $prefix.'%')
                ->limit(500)->get(['entity_id', 'alias', 'alias_type'])
                ->each(function ($a) use ($surface, $add) {
                    $s = Similarity::name($surface, $a->alias);
                    if ($s >= 0.7) {
                        $add($a->entity_id, $s * ($a->alias_type === 'historical' ? 0.85 : 0.95), 'alias_fuzzy');
                    }
                });
        }

        if (! $scores) {
            return collect();
        }

        $entities = Entity::whereIn('id', array_keys($scores))
            ->when($typeIds, fn ($q) => $q->whereIn('entity_type_id', $typeIds))
            ->get()->keyBy('id');

        $parentPath = null;
        if (! empty($context['parent_place_id'])) {
            $parentPath = Place::where('entity_id', $context['parent_place_id'])->value('path');
        }

        $out = [];
        foreach ($scores as $id => $info) {
            $entity = $entities->get($id);
            if (! $entity) {
                continue;
            }
            $score = $info['score'];
            $reasons = $info['reasons'];
            // Context: inside the expected parent place → boost; outside → penalty.
            if ($parentPath) {
                $path = Place::where('entity_id', $id)->value('path');
                if ($path && str_starts_with($path, $parentPath)) {
                    $score += 0.04;
                    $reasons[] = 'within_parent';
                } elseif ($path) {
                    $score -= 0.08;
                    $reasons[] = 'outside_parent';
                }
            }
            if (isset($context['lat'], $context['lng']) && $entity->latitude !== null) {
                $km = Similarity::haversineKm($context['lat'], $context['lng'], $entity->latitude, $entity->longitude);
                if ($km <= (float) config('museum.resolution.geo_duplicate_km', 2.0)) {
                    $score += 0.04;
                    $reasons[] = 'geo_near';
                } elseif ($km > 50) {
                    $score -= 0.15;
                    $reasons[] = 'geo_far';
                }
            }
            $target = $this->followMerges($entity);
            $out[$target->id] = ['entity' => $target, 'score' => min($score, 1.0), 'reasons' => $reasons];
        }

        return collect($out)->sortByDesc('score')->values()->take($limit);
    }

    private function followMerges(Entity $e): Entity
    {
        $guard = 0;
        while ($e->merged_into_id && $guard++ < 10) {
            $e = Entity::find($e->merged_into_id) ?? $e;
        }

        return $e;
    }
}
