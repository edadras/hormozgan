<?php

namespace App\Http\Controllers\Museum\Api;

use App\Models\Museum\Entity;
use App\Models\Museum\EntityRelationship;
use App\Models\Museum\Fact;
use App\Models\Museum\Source;
use Illuminate\Http\Request;

class KnowledgeController extends ApiController
{
    /** Fact → Source → Page → Extract (+ revisions and alternative values). */
    public function fact(Request $r, string $uuid)
    {
        return $this->cached($r, function () use ($uuid) {
            $fact = Fact::public()->where('uuid', $uuid)->whereHas('entity', fn ($q) => $q->published())->firstOrFail();

            return ['data' => $this->presenter->provenance($fact)];
        });
    }

    public function source(Request $r, string $uuid)
    {
        return $this->cached($r, function () use ($uuid) {
            $s = Source::where('uuid', $uuid)->firstOrFail();
            $facts = Fact::public()->whereIn('id', fn ($q) => $q->select('fact_id')->from('museum_fact_sources')->where('source_id', $s->id))
                ->whereHas('entity', fn ($q) => $q->published())->count();

            return ['data' => $this->presenter->source($s) + ['public_facts' => $facts, 'notes' => $s->notes, 'topics' => $s->topics, 'regions' => $s->regions,
                'crawl_policy' => $s->crawl_policy, 'retrieved_at' => $s->retrieved_at?->toIso8601String()]];
        });
    }

    /** One-hop knowledge graph around an entity (nodes + edges), published only. */
    public function graph(Request $r, string $slug)
    {
        return $this->cached($r, function () use ($slug) {
            $e = Entity::published()->with('type')->where('slug', $slug)->firstOrFail();
            $edges = EntityRelationship::with(['type', 'subject.type', 'object.type'])
                ->where(fn ($q) => $q->where('subject_id', $e->id)->orWhere('object_id', $e->id))
                ->whereHas('fact', fn ($q) => $q->public())->limit(500)->get()
                ->filter(fn ($rel) => $rel->subject?->isPublished() && $rel->object?->isPublished());
            $nodes = $edges->flatMap(fn ($rel) => [$rel->subject, $rel->object])->push($e)->unique('id')
                ->map(fn ($n) => $this->presenter->summary($n))->values();

            return ['nodes' => $nodes, 'edges' => $edges->map(fn ($rel) => [
                'from' => $rel->subject_id, 'to' => $rel->object_id, 'relation' => $rel->type->key, 'label' => $rel->type->name_fa,
                'fact' => url('/museum/facts/'.$rel->fact->uuid),
            ])->values()];
        });
    }

    public function stats(Request $r)
    {
        return $this->cached($r, fn () => ['data' => app(\App\Museum\Services\QualityMetrics::class)->publicCounts()]);
    }
}
