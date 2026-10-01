<?php

namespace App\Museum\Services;

use App\Models\Museum\EntityAlias;
use App\Models\Museum\Mention;
use App\Museum\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Gazetteer-based mention detection: finds known entity names (any alias, including
 * historical names) inside a text span and links the span to the entity as a *suggested*
 * mention. Used to connect interview segments and document pages to place/word pages.
 * Ambiguous names (several entities) are linked to all candidates with lower confidence,
 * for a human to confirm.
 */
class MentionLinker
{
    public function link(Model $mentionable, string $text, int $maxNgram = 4): int
    {
        $tokens = TextNormalizer::tokens($text);
        if (! $tokens) {
            return 0;
        }
        $grams = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            for ($len = 1; $len <= $maxNgram && $i + $len <= $n; $len++) {
                $g = implode(' ', array_slice($tokens, $i, $len));
                if (mb_strlen(str_replace(' ', '', $g)) >= 3) {
                    $grams[$g] = true;
                }
            }
        }
        $created = 0;
        foreach (array_chunk(array_keys($grams), 500) as $chunk) {
            $aliases = EntityAlias::whereIn('normalized_alias', $chunk)->get(['entity_id', 'alias', 'normalized_alias']);
            foreach ($aliases->groupBy('normalized_alias') as $norm => $group) {
                $entityIds = $group->pluck('entity_id')->unique();
                $confidence = $entityIds->count() === 1 ? 0.8 : round(0.8 / $entityIds->count(), 4);
                foreach ($entityIds as $eid) {
                    $m = Mention::firstOrCreate([
                        'mentionable_type' => $mentionable->getMorphClass(),
                        'mentionable_id' => $mentionable->getKey(),
                        'entity_id' => $eid,
                        'surface_form' => mb_substr($group->first()->alias, 0, 500),
                    ], [
                        'confidence_score' => $confidence,
                        'status' => 'suggested',
                        'detected_by' => 'gazetteer',
                    ]);
                    $created += $m->wasRecentlyCreated ? 1 : 0;
                }
            }
        }

        return $created;
    }
}
