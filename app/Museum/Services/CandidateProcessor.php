<?php

namespace App\Museum\Services;

use App\Models\Museum\Entity;
use App\Models\Museum\ExtractionCandidate;
use App\Models\Museum\Property;
use App\Models\Museum\Source;
use App\Models\Museum\SourceExtract;
use App\Museum\Enums\VerificationStatus;

/**
 * Discovery Engine + routing of extraction candidates into the knowledge base.
 *
 *  entity           → resolved: linked ("merged"); ambiguous: in_review; unknown: discovery
 *                     (needs_evidence until seen in N independent sources, then in_review)
 *  fact/relationship→ subject resolved: stored as an `ai_extracted` fact with its extract as
 *                     citation (never public until a human verifies); otherwise in_review
 *  historical_name  → subject resolved: stored as ai_extracted historical name + alias
 *
 * Nothing here publishes anything, and nothing creates a new entity without a human.
 */
class CandidateProcessor
{
    public function __construct(
        private EntityResolver $resolver,
        private FactService $facts,
        private EntityService $entities,
    ) {}

    /** @return array<string,int> counters */
    public function processPending(int $limit = 500): array
    {
        $stats = ['matched' => 0, 'facts' => 0, 'in_review' => 0, 'needs_evidence' => 0, 'conflicts' => 0, 'errors' => 0];
        ExtractionCandidate::where('status', 'pending')->orderBy('id')->limit($limit)->get()
            ->each(function (ExtractionCandidate $c) use (&$stats) {
                try {
                    $outcome = $this->process($c);
                    $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
                } catch (\Throwable $e) {
                    $c->forceFill(['status' => 'in_review', 'rejection_reason' => 'processing error: '.mb_substr($e->getMessage(), 0, 200)])->save();
                    $stats['errors']++;
                }
            });

        return $stats;
    }

    public function process(ExtractionCandidate $c): string
    {
        return match ($c->kind) {
            'entity' => $this->entity($c),
            'fact' => $this->fact($c),
            'relationship' => $this->relationship($c),
            'historical_name' => $this->historicalName($c),
            default => $this->review($c, 'unsupported candidate kind'),
        };
    }

    private function entity(ExtractionCandidate $c): string
    {
        $r = $this->resolver->resolve((string) $c->surface_form, ['types' => $c->entity_type_key ? [$c->entity_type_key] : []]);
        if ($r['decision'] === EntityResolver::MATCHED) {
            $c->forceFill(['status' => 'merged', 'matched_entity_id' => $r['entity']->id, 'match_score' => $r['score']])->save();

            return 'matched';
        }
        if ($r['decision'] === EntityResolver::REVIEW) {
            $c->forceFill(['status' => 'in_review', 'matched_entity_id' => $r['entity']?->id, 'match_score' => $r['score']])->save();

            return 'in_review';
        }

        // Discovery: aggregate independent evidence for the same unknown name and type.
        $siblings = ExtractionCandidate::where('kind', 'entity')
            ->where('normalized_form', $c->normalized_form)
            ->where('entity_type_key', $c->entity_type_key)
            ->whereIn('status', ['pending', 'needs_evidence', 'in_review'])
            ->get();
        $sourceIds = $siblings->pluck('source_id')->filter()->unique()->values()->all();
        $min = (int) config('museum.extraction.discovery_min_sources', 2);
        $status = count($sourceIds) >= $min ? 'in_review' : 'needs_evidence';
        foreach ($siblings as $s) {
            $s->forceFill(['evidence_count' => count($sourceIds), 'evidence_source_ids' => $sourceIds, 'status' => $status])->save();
        }

        return $status;
    }

    private function fact(ExtractionCandidate $c): string
    {
        $p = $c->payload;
        $subject = $this->resolveSubject($p['subject'] ?? '', $p['subject_type'] ?? null);
        if (! $subject) {
            return $this->review($c, 'subject not resolved');
        }
        $property = Property::where('key', $p['property'] ?? '')->first();
        if (! $property || ! $property->appliesTo($subject->type->key)) {
            return $this->review($c, 'property does not apply to subject type');
        }
        $value = $p['value'];
        if ($property->datatype === 'entity') {
            $obj = $this->resolveSubject((string) $value, null);
            if (! $obj) {
                return $this->review($c, 'object entity not resolved');
            }
            $value = $obj;
        }
        $qualifiers = null;
        if (! empty($p['qualifiers'])) {
            $decoded = json_decode((string) $p['qualifiers'], true);
            $qualifiers = is_array($decoded) ? $decoded : ['note' => (string) $p['qualifiers']];
        }

        return $this->storeFact($c, $subject, $property, $value, $qualifiers);
    }

    private function relationship(ExtractionCandidate $c): string
    {
        $p = $c->payload;
        $subject = $this->resolveSubject($p['subject'] ?? '', $p['subject_type'] ?? null);
        $object = $this->resolveSubject($p['object'] ?? '', $p['object_type'] ?? null);
        if (! $subject || ! $object) {
            return $this->review($c, 'subject or object not resolved');
        }
        $property = Property::whereHas('relationshipType', fn ($q) => $q->where('key', $p['relation']))
            ->where('datatype', 'entity')->get()
            ->first(fn (Property $prop) => $prop->appliesTo($subject->type->key));
        if (! $property) {
            return $this->review($c, 'no property materializes relation '.$p['relation'].' for this subject type');
        }

        return $this->storeFact($c, $subject, $property, $object, null);
    }

    private function historicalName(ExtractionCandidate $c): string
    {
        $p = $c->payload;
        $subject = $this->resolveSubject($p['entity'] ?? '', $p['entity_type'] ?? null);
        if (! $subject) {
            return $this->review($c, 'entity not resolved');
        }
        $hn = $this->entities->addHistoricalName($subject, [
            'name' => $p['historical_name'],
            'period_label' => $p['period'] ?: null,
            'source_id' => $c->source_id,
            'source_extract_id' => $c->source_extract_id,
            'page_number' => SourceExtract::find($c->source_extract_id)?->page_number,
            'verification_status' => VerificationStatus::AiExtracted->value,
        ]);
        $c->forceFill(['status' => 'accepted', 'matched_entity_id' => $subject->id, 'result_type' => $hn->getMorphClass(), 'result_id' => $hn->id])->save();

        return 'facts';
    }

    private function storeFact(ExtractionCandidate $c, Entity $subject, Property $property, mixed $value, ?array $qualifiers): string
    {
        $source = $c->source_id ? Source::find($c->source_id) : null;
        $fact = $this->facts->assert($subject, $property, $value, [
            'source' => $source,
            'extract' => $c->source_extract_id ? SourceExtract::find($c->source_extract_id) : null,
            'quote' => $c->evidence_quote,
            'confidence' => $c->confidence_score,
            'status' => VerificationStatus::AiExtracted->value,
            'method' => 'ai',
            'qualifiers' => $qualifiers,
            'extraction_job_id' => $c->extraction_job_id,
        ]);
        $c->forceFill(['status' => 'accepted', 'matched_entity_id' => $subject->id, 'result_type' => $fact->getMorphClass(), 'result_id' => $fact->id])->save();

        return $fact->conflict_id ? 'conflicts' : 'facts';
    }

    private function resolveSubject(string $name, ?string $type): ?Entity
    {
        if (trim($name) === '') {
            return null;
        }
        $r = $this->resolver->resolve($name, ['types' => $type ? [$type] : []]);

        return $r['decision'] === EntityResolver::MATCHED ? $r['entity'] : null;
    }

    private function review(ExtractionCandidate $c, string $reason): string
    {
        $c->forceFill(['status' => 'in_review', 'rejection_reason' => $reason])->save();

        return 'in_review';
    }

    /**
     * Human decision on an in_review entity candidate: link to an existing entity or create a
     * new one (unverified, draft, not public). The candidate's quote becomes a sourced
     * description extract only through the reviewer's explicit action.
     */
    public function acceptEntity(ExtractionCandidate $c, ?Entity $linkTo, ?int $userId): Entity
    {
        $entity = $linkTo ?? $this->entities->create($c->entity_type_key ?? 'place', [
            'canonical_name' => $c->surface_form,
            'verification_status' => VerificationStatus::Unverified->value,
            'visibility' => 'draft',
        ], [], $userId);
        if ($linkTo) {
            $this->entities->addAlias($linkTo, (string) $c->surface_form, ['type' => 'spelling_variant', 'source_id' => $c->source_id]);
        }
        ExtractionCandidate::where('kind', 'entity')->where('normalized_form', $c->normalized_form)
            ->where('entity_type_key', $c->entity_type_key)->whereIn('status', ['needs_evidence', 'in_review', 'pending'])
            ->update(['status' => 'accepted', 'matched_entity_id' => $entity->id, 'reviewed_by' => $userId, 'reviewed_at' => now()]);
        app(VerificationService::class)->log($c, 'approve', 'in_review', 'accepted', $userId, null, ['entity_id' => $entity->id]);

        return $entity;
    }

    public function reject(ExtractionCandidate $c, ?int $userId, string $reason): void
    {
        $c->forceFill(['status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_by' => $userId, 'reviewed_at' => now()])->save();
        app(VerificationService::class)->log($c, 'reject', null, 'rejected', $userId, $reason);
    }
}
