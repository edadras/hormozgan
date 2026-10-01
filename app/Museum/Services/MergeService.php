<?php

namespace App\Museum\Services;

use App\Models\Museum\DuplicateCandidate;
use App\Models\Museum\Entity;
use App\Models\Museum\EntityAlias;
use App\Models\Museum\EntityMerge;
use App\Models\Museum\EntityRelationship;
use App\Models\Museum\Fact;
use App\Models\Museum\HistoricalName;
use App\Models\Museum\Mention;
use App\Museum\Support\CacheVersion;
use Illuminate\Support\Facades\DB;

/**
 * Non-destructive merge: the duplicate keeps its row (merged_into_id), a full snapshot is
 * stored, and all its names become aliases of the surviving entity.
 */
class MergeService
{
    public function __construct(private EntityService $entities, private FactService $facts, private VerificationService $verification) {}

    public function merge(Entity $duplicate, Entity $into, ?int $userId, string $reason): Entity
    {
        if ($duplicate->id === $into->id) {
            throw new \InvalidArgumentException('Cannot merge an entity into itself.');
        }
        if ($into->merged_into_id) {
            throw new \InvalidArgumentException('Target entity was itself merged; merge into its successor.');
        }

        return DB::transaction(function () use ($duplicate, $into, $userId, $reason) {
            EntityMerge::create([
                'merged_entity_id' => $duplicate->id,
                'into_entity_id' => $into->id,
                'snapshot' => [
                    'entity' => $duplicate->toArray(),
                    'aliases' => $duplicate->aliases()->get()->toArray(),
                    'fact_ids' => $duplicate->facts()->pluck('id'),
                ],
                'reason' => $reason,
                'merged_by' => $userId,
            ]);

            foreach (['canonical_name', 'name_fa', 'name_en', 'name_ar', 'name_local', 'name_local_latin'] as $f) {
                if ($duplicate->{$f}) {
                    $this->entities->addAlias($into, $duplicate->{$f}, ['type' => 'spelling_variant']);
                }
            }
            foreach (EntityAlias::where('entity_id', $duplicate->id)->get() as $alias) {
                $this->entities->addAlias($into, $alias->alias, ['type' => $alias->alias_type, 'language' => $alias->language, 'source_id' => $alias->source_id]);
            }
            HistoricalName::where('entity_id', $duplicate->id)->update(['entity_id' => $into->id]);
            Mention::where('entity_id', $duplicate->id)->update(['entity_id' => $into->id]);

            // Facts move with recomputed conflict keys; identical values are corroborations.
            foreach (Fact::where('entity_id', $duplicate->id)->get() as $fact) {
                $fact->entity_id = $into->id;
                $fact->conflict_key = $this->facts->conflictKey($into->id, $fact->property_id, $fact->scope_place_id, $fact->qualifiers ?? []);
                $fact->save();
            }
            Fact::where('value_entity_id', $duplicate->id)->update(['value_entity_id' => $into->id]);
            EntityRelationship::where('subject_id', $duplicate->id)->update(['subject_id' => $into->id]);
            EntityRelationship::where('object_id', $duplicate->id)->update(['object_id' => $into->id]);
            DB::table('museum_mediables')->where('mediable_type', $duplicate->getMorphClass())->where('mediable_id', $duplicate->id)
                ->get()->each(function ($row) use ($into) {
                    DB::table('museum_mediables')->updateOrInsert(
                        ['media_id' => $row->media_id, 'mediable_type' => $row->mediable_type, 'mediable_id' => $into->id, 'role' => $row->role],
                        ['sort' => $row->sort]
                    );
                });
            if (! $into->wikidata_id && $duplicate->wikidata_id) {
                $qid = $duplicate->wikidata_id;
                $duplicate->forceFill(['wikidata_id' => null])->save();
                $into->forceFill(['wikidata_id' => $qid])->save();
            }

            $duplicate->forceFill(['merged_into_id' => $into->id, 'visibility' => 'hidden'])->save();
            DuplicateCandidate::where(function ($q) use ($duplicate, $into) {
                $q->where(['entity_a_id' => $duplicate->id, 'entity_b_id' => $into->id])
                    ->orWhere(fn ($w) => $w->where(['entity_a_id' => $into->id, 'entity_b_id' => $duplicate->id]));
            })->update(['status' => 'merged', 'reviewed_by' => $userId, 'reviewed_at' => now()]);

            $this->facts->refreshEntityCounters($into->id);
            $this->verification->log($duplicate, 'merge', null, 'merged', $userId, $reason, ['into' => $into->id]);
            CacheVersion::bump();

            return $into->fresh();
        });
    }
}
