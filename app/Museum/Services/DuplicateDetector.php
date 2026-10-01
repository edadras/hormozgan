<?php

namespace App\Museum\Services;

use App\Models\Museum\DuplicateCandidate;
use App\Models\Museum\Entity;
use App\Models\Museum\EntityAlias;
use App\Models\Museum\Place;
use App\Museum\Support\Similarity;

/**
 * Finds likely duplicate entities and queues them for human review. It never merges
 * automatically: two villages may legitimately share a name.
 */
class DuplicateDetector
{
    /** @return int number of new duplicate candidates */
    public function scan(Entity $entity): int
    {
        $created = 0;
        $keys = EntityAlias::where('entity_id', $entity->id)->pluck('compact_key')->unique()->filter();
        if ($keys->isEmpty()) {
            return 0;
        }
        $others = EntityAlias::whereIn('compact_key', $keys)->where('entity_id', '!=', $entity->id)
            ->pluck('entity_id')->unique();
        $myPlace = Place::find($entity->id);

        foreach (Entity::whereIn('id', $others)->whereNull('merged_into_id')->get() as $other) {
            $score = 0.8;
            $evidence = ['shared_alias' => true];
            if ($other->entity_type_id === $entity->entity_type_id) {
                $score += 0.05;
            }
            if ($entity->wikidata_id && $other->wikidata_id && $entity->wikidata_id !== $other->wikidata_id) {
                // Different Wikidata items: almost certainly different things with the same name.
                continue;
            }
            if ($entity->latitude !== null && $other->latitude !== null) {
                $km = Similarity::haversineKm($entity->latitude, $entity->longitude, $other->latitude, $other->longitude);
                $evidence['distance_km'] = round($km, 2);
                if ($km <= (float) config('museum.resolution.geo_duplicate_km', 2.0)) {
                    $score += 0.1;
                } elseif ($km > 20) {
                    continue; // same name, far apart: homonymous places
                }
            }
            if ($myPlace && ($otherPlace = Place::find($other->id)) && $myPlace->parent_id && $otherPlace->parent_id
                && $myPlace->parent_id !== $otherPlace->parent_id) {
                $score -= 0.15;
                $evidence['different_parent'] = true;
            }
            [$a, $b] = $entity->id < $other->id ? [$entity->id, $other->id] : [$other->id, $entity->id];
            $dc = DuplicateCandidate::firstOrCreate(
                ['entity_a_id' => $a, 'entity_b_id' => $b],
                ['score' => min(0.99, $score), 'method' => 'alias_compact', 'evidence' => $evidence]
            );
            $created += $dc->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
