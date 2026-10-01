<?php

namespace App\Museum\Services;

use App\Models\Museum\Entity;
use App\Models\Museum\EntityRelationship;
use App\Models\Museum\Fact;
use App\Models\Museum\FactConflict;
use App\Models\Museum\FactRevision;
use App\Models\Museum\FactSource;
use App\Models\Museum\Property;
use App\Models\Museum\Source;
use App\Models\Museum\SourceExtract;
use App\Museum\Enums\ValueType;
use App\Museum\Enums\VerificationStatus;
use App\Museum\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only write path for facts.
 *
 * - Source-first: a fact cannot be raised to source_verified or above without a citation.
 * - UNKNOWN is a first-class value; nothing is ever guessed.
 * - Same value from another source → corroboration (source attached), not a duplicate.
 * - Different value for a single-valued property → FactConflict; nothing is deleted.
 * - Every change writes a FactRevision (previous value, new value, editor, reason, source).
 * - Entity-valued facts materialize a graph edge backed by the fact.
 */
class FactService
{
    public const UNKNOWN = '__UNKNOWN__';

    /**
     * @param  mixed  $value  scalar, Entity, ['lat'=>,'lng'=>], [from,to] year range, null/UNKNOWN
     * @param  array{
     *   source?: Source|null, extract?: SourceExtract|null, page?: string|null, locator?: string|null, quote?: string|null,
     *   confidence?: float|null, status?: string, method?: string, scope_place_id?: int|null, qualifiers?: array|null,
     *   language?: string|null, user_id?: int|null, import_batch_id?: int|null, extraction_job_id?: int|null,
     *   localized?: array<string,string>
     * }  $options
     */
    public function assert(Entity $entity, string|Property $property, mixed $value, array $options = []): Fact
    {
        $property = $property instanceof Property ? $property : Property::byKey($property);
        $typeKey = $entity->type?->key;
        if (! $property->appliesTo($typeKey)) {
            throw new InvalidArgumentException("Property [{$property->key}] does not apply to entity type [{$typeKey}].");
        }
        $status = VerificationStatus::from($options['status'] ?? VerificationStatus::Unverified->value);
        $source = $options['source'] ?? null;
        if ($status->atLeast(VerificationStatus::SourceVerified) && ! $source) {
            throw new InvalidArgumentException('A fact cannot be marked source-verified or higher without a source.');
        }

        $columns = $this->coerce($property, $value);
        $qualifiers = $this->qualifiers($property, $options['qualifiers'] ?? null);
        $scopePlaceId = $options['scope_place_id'] ?? null;
        $conflictKey = $this->conflictKey($entity->id, $property->id, $scopePlaceId, $qualifiers);

        return DB::transaction(function () use ($entity, $property, $columns, $qualifiers, $scopePlaceId, $conflictKey, $status, $source, $options) {
            $existing = Fact::where('conflict_key', $conflictKey)
                ->where('verification_status', '!=', VerificationStatus::Rejected->value)
                ->lockForUpdate()->get();

            // Corroboration: identical value already asserted.
            $same = $existing->firstWhere('value_hash', $columns['value_hash']);
            if ($same) {
                if ($source) {
                    $this->attachSource($same, $source, $options);
                }
                if ($status->rank() > $same->status()->rank()) {
                    $this->changeStatus($same, $status, $options['user_id'] ?? null, 'Corroborated by additional source', $source);
                }

                return $same->fresh();
            }

            $fact = new Fact(array_merge($columns, [
                'entity_id' => $entity->id,
                'property_id' => $property->id,
                'language' => $options['language'] ?? null,
                'scope_place_id' => $scopePlaceId,
                'qualifiers' => $qualifiers ?: null,
                'conflict_key' => $conflictKey,
                'confidence_score' => $options['confidence'] ?? null,
                'verification_status' => $status->value,
                'extraction_method' => $options['method'] ?? 'manual',
                'extraction_job_id' => $options['extraction_job_id'] ?? null,
                'import_batch_id' => $options['import_batch_id'] ?? null,
                'created_by' => $options['user_id'] ?? null,
            ]));
            $fact->save();

            foreach ($options['localized'] ?? [] as $locale => $display) {
                $fact->values()->create(['locale' => $locale, 'display_value' => $display]);
            }

            $this->revision($fact, 'created', null, $fact->valueSnapshot(), null, $status->value, $options['user_id'] ?? null, $options['reason'] ?? null, $source?->id);
            if ($source) {
                $this->attachSource($fact, $source, $options + ['_creating' => true]);
            }
            $this->syncRelationship($fact, $property);
            $this->detectConflict($fact, $property, $existing);
            $this->refreshEntityCounters($entity->id);

            return $fact->fresh();
        });
    }

    /** Records that the given property is explicitly UNKNOWN in a source/assessment. */
    public function assertUnknown(Entity $entity, string $propertyKey, array $options = []): Fact
    {
        return $this->assert($entity, $propertyKey, self::UNKNOWN, $options);
    }

    public function attachSource(Fact $fact, Source $source, array $options = []): FactSource
    {
        $extract = $options['extract'] ?? null;
        $fs = FactSource::firstOrCreate(
            ['fact_id' => $fact->id, 'source_id' => $source->id, 'source_extract_id' => $extract?->id],
            [
                'page_number' => $options['page'] ?? $extract?->page_number,
                'locator' => $options['locator'] ?? $extract?->locator,
                'quote' => $options['quote'] ?? null,
                'support' => $options['support'] ?? 'supports',
                'confidence_score' => $options['confidence'] ?? null,
                'verification_status' => $options['status'] ?? 'unverified',
                'added_by' => $options['user_id'] ?? null,
            ]
        );
        if ($fs->wasRecentlyCreated) {
            $fact->sources_count = FactSource::where('fact_id', $fact->id)->where('support', '!=', 'contradicts')->count();
            $fact->saveQuietly();
            if (empty($options['_creating'])) {
                $this->revision($fact, 'source_added', null, ['source_id' => $source->id, 'extract_id' => $extract?->id], null, null, $options['user_id'] ?? null, null, $source->id);
            }
            $this->refreshEntityCounters($fact->entity_id);
        }

        return $fs;
    }

    /** Edits never overwrite history: the previous value is kept in a revision. */
    public function updateValue(Fact $fact, mixed $value, ?int $editorId, string $reason, ?Source $source = null): Fact
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to change a fact value.');
        }
        $property = $fact->property;
        $columns = $this->coerce($property, $value);
        if ($columns['value_hash'] === $fact->value_hash) {
            return $fact;
        }

        return DB::transaction(function () use ($fact, $property, $columns, $editorId, $reason, $source) {
            $previous = $fact->valueSnapshot();
            $fact->fill($columns);
            $fact->save();
            $this->revision($fact, 'value_changed', $previous, $fact->valueSnapshot(), null, null, $editorId, $reason, $source?->id);
            if ($source) {
                $this->attachSource($fact, $source, ['user_id' => $editorId]);
            }
            $this->syncRelationship($fact, $property);
            $others = Fact::where('conflict_key', $fact->conflict_key)->where('id', '!=', $fact->id)
                ->where('verification_status', '!=', VerificationStatus::Rejected->value)->get();
            $this->detectConflict($fact, $property, $others);

            return $fact->fresh();
        });
    }

    public function changeStatus(Fact $fact, VerificationStatus $to, ?int $userId, ?string $notes = null, ?Source $source = null): Fact
    {
        $from = $fact->verification_status;
        if ($from === $to->value) {
            return $fact;
        }
        if ($to->atLeast(VerificationStatus::SourceVerified) && $fact->sources()->count() === 0 && ! $source) {
            throw new InvalidArgumentException('Cannot verify a fact that has no source.');
        }

        return DB::transaction(function () use ($fact, $from, $to, $userId, $notes, $source) {
            $fact->verification_status = $to->value;
            if ($to->rank() >= VerificationStatus::SourceVerified->rank() || $to === VerificationStatus::Rejected) {
                $fact->reviewed_by = $userId;
                $fact->reviewed_at = now();
            }
            $fact->save();
            $this->revision($fact, 'status_changed', null, null, $from, $to->value, $userId, $notes, $source?->id);
            app(VerificationService::class)->log($fact, 'status_change', $from, $to->value, $userId, $notes);
            if ($fact->relationship) {
                $fact->relationship->update(['verification_status' => $to->value]);
            }
            $this->refreshEntityCounters($fact->entity_id);

            return $fact;
        });
    }

    /**
     * Expert decision on a conflict. Facts are never deleted: the preferred one is flagged,
     * the others keep their status (or are rejected only if the expert says so).
     */
    public function resolveConflict(FactConflict $conflict, ?int $preferredFactId, string $resolution, ?string $note, ?int $userId, bool $rejectOthers = false): FactConflict
    {
        return DB::transaction(function () use ($conflict, $preferredFactId, $resolution, $note, $userId, $rejectOthers) {
            $facts = $conflict->facts()->get();
            if ($preferredFactId && ! $facts->contains('id', $preferredFactId)) {
                throw new InvalidArgumentException('Preferred fact is not part of this conflict.');
            }
            foreach ($facts as $f) {
                $f->is_preferred = $f->id === $preferredFactId;
                $f->saveQuietly();
                if ($rejectOthers && $preferredFactId && $f->id !== $preferredFactId) {
                    $this->changeStatus($f, VerificationStatus::Rejected, $userId, 'Rejected in conflict resolution: '.$note);
                }
            }
            $conflict->update([
                'status' => 'resolved',
                'resolution' => $resolution,
                'preferred_fact_id' => $preferredFactId,
                'resolution_note' => $note,
                'resolved_by' => $userId,
                'resolved_at' => now(),
            ]);
            app(VerificationService::class)->log($conflict, 'resolve_conflict', 'open', 'resolved', $userId, $note, ['preferred_fact_id' => $preferredFactId]);

            return $conflict;
        });
    }

    // ------------------------------------------------------------------ internals

    /** Maps a PHP value to typed fact columns + a stable value hash. */
    public function coerce(Property $property, mixed $value): array
    {
        $cols = [
            'value_text' => null, 'value_number' => null, 'value_year_from' => null, 'value_year_to' => null,
            'value_date' => null, 'value_entity_id' => null, 'value_json' => null, 'is_unknown' => false,
        ];
        if ($value === null || $value === self::UNKNOWN || (is_string($value) && strtoupper(trim($value)) === 'UNKNOWN')) {
            $cols['value_type'] = ValueType::Unknown->value;
            $cols['is_unknown'] = true;
            $cols['value_normalized'] = 'unknown';
            $cols['value_hash'] = hash('sha256', 'unknown');

            return $cols;
        }
        $type = $property->datatype;
        $cols['value_type'] = $type;
        switch ($type) {
            case 'entity':
                $id = $value instanceof Entity ? $value->id : (int) $value;
                if (! $id || ! Entity::whereKey($id)->exists()) {
                    throw new InvalidArgumentException("Property [{$property->key}] requires an existing entity.");
                }
                $cols['value_entity_id'] = $id;
                $norm = 'entity:'.$id;
                break;
            case 'integer':
            case 'decimal':
                $num = is_string($value)
                    ? strtr(TextNormalizer::asciiDigits(trim($value)), ['٬' => '', ',' => '', ' ' => '', '٫' => '.'])
                    : $value;
                if (! is_numeric($num)) {
                    throw new InvalidArgumentException("Property [{$property->key}] requires a number, got [".json_encode($value, JSON_UNESCAPED_UNICODE).'].');
                }
                $cols['value_number'] = $type === 'integer' ? (int) round((float) $num) : (float) $num;
                $norm = (string) $cols['value_number'];
                break;
            case 'year':
                [$from, $to] = is_array($value) ? [$value[0] ?? $value['from'] ?? null, $value[1] ?? $value['to'] ?? null] : [$value, $value];
                $from = is_string($from) ? TextNormalizer::asciiDigits(trim($from)) : $from;
                $to = is_string($to) ? TextNormalizer::asciiDigits(trim($to)) : $to;
                if (! is_numeric($from)) {
                    throw new InvalidArgumentException("Property [{$property->key}] requires a year.");
                }
                $cols['value_year_from'] = (int) $from;
                $cols['value_year_to'] = is_numeric($to) ? (int) $to : (int) $from;
                $norm = $cols['value_year_from'].'-'.$cols['value_year_to'];
                break;
            case 'date':
                $cols['value_date'] = (string) $value;
                $norm = $cols['value_date'];
                break;
            case 'boolean':
                $cols['value_number'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $norm = (string) $cols['value_number'];
                break;
            case 'geo':
                $lat = (float) ($value['lat'] ?? $value[0] ?? 0);
                $lng = (float) ($value['lng'] ?? $value[1] ?? 0);
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    throw new InvalidArgumentException('Invalid coordinates.');
                }
                $cols['value_json'] = ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
                $norm = round($lat, 4).','.round($lng, 4);
                break;
            case 'json':
                $cols['value_json'] = (array) $value;
                $norm = json_encode($value, JSON_UNESCAPED_UNICODE);
                break;
            default: // string, text
                $text = trim((string) $value);
                if ($text === '') {
                    throw new InvalidArgumentException("Empty value for [{$property->key}]; use UNKNOWN instead of an empty string.");
                }
                $cols['value_text'] = $text;
                $norm = TextNormalizer::normalize($text);
        }
        $cols['value_normalized'] = mb_substr($norm, 0, 500);
        $cols['value_hash'] = hash('sha256', $type.'|'.$norm);

        return $cols;
    }

    private function qualifiers(Property $property, ?array $qualifiers): array
    {
        if (! $qualifiers) {
            return [];
        }
        ksort($qualifiers);

        return $qualifiers;
    }

    /** Facts compete only when they share entity, property, place scope and identifying qualifiers. */
    public function conflictKey(int $entityId, int $propertyId, ?int $scopePlaceId, array $qualifiers): string
    {
        $property = Property::find($propertyId);
        $keyed = array_intersect_key($qualifiers, array_flip($property?->qualifier_keys ?? []));
        ksort($keyed);

        return $entityId.'|'.$propertyId.'|'.($scopePlaceId ?? '-').'|'.substr(hash('sha256', json_encode($keyed)), 0, 16);
    }

    private function detectConflict(Fact $fact, Property $property, $others): void
    {
        if ($property->is_multivalued || $fact->is_unknown) {
            return;
        }
        $rivals = collect($others)->filter(fn (Fact $o) => $o->id !== $fact->id && ! $o->is_unknown && $o->value_hash !== $fact->value_hash);
        if ($rivals->isEmpty()) {
            return;
        }
        $conflict = FactConflict::firstOrCreate(
            ['conflict_key' => $fact->conflict_key, 'status' => 'open'],
            ['entity_id' => $fact->entity_id, 'property_id' => $property->id]
        );
        Fact::whereIn('id', $rivals->pluck('id')->push($fact->id))->update(['conflict_id' => $conflict->id]);
        app(VerificationService::class)->log($conflict, 'conflict_detected', null, 'open', null, null, [
            'fact_ids' => $rivals->pluck('id')->push($fact->id)->values(),
        ]);
    }

    private function syncRelationship(Fact $fact, Property $property): void
    {
        if ($fact->value_type !== 'entity' || ! $property->relationship_type_id || ! $fact->value_entity_id) {
            EntityRelationship::where('fact_id', $fact->id)->delete();

            return;
        }
        EntityRelationship::updateOrCreate(['fact_id' => $fact->id], [
            'subject_id' => $fact->entity_id,
            'relationship_type_id' => $property->relationship_type_id,
            'object_id' => $fact->value_entity_id,
            'valid_from_year' => $fact->qualifiers['valid_from'] ?? null,
            'valid_to_year' => $fact->qualifiers['valid_to'] ?? null,
            'confidence_score' => $fact->confidence_score,
            'verification_status' => $fact->verification_status,
        ]);
    }

    private function revision(Fact $fact, string $type, ?array $prev, ?array $new, ?string $prevStatus, ?string $newStatus, ?int $editorId, ?string $reason, ?int $sourceId): void
    {
        $next = (int) FactRevision::where('fact_id', $fact->id)->max('revision') + 1;
        FactRevision::create([
            'fact_id' => $fact->id, 'revision' => $next, 'change_type' => $type,
            'previous_value' => $prev, 'new_value' => $new, 'previous_status' => $prevStatus, 'new_status' => $newStatus,
            'editor_id' => $editorId, 'reason' => $reason, 'source_id' => $sourceId, 'created_at' => now(),
        ]);
        if ($fact->revision !== $next) {
            $fact->revision = $next;
            $fact->saveQuietly();
        }
    }

    /** Bulk imports defer counter refreshes and flush them once at the end. */
    public bool $deferCounters = false;

    private array $dirtyEntities = [];

    public function flushCounters(): void
    {
        $ids = array_keys($this->dirtyEntities);
        $this->dirtyEntities = [];
        $deferred = $this->deferCounters;
        $this->deferCounters = false;
        foreach ($ids as $id) {
            $this->refreshEntityCounters($id);
        }
        $this->deferCounters = $deferred;
    }

    public function refreshEntityCounters(int $entityId): void
    {
        if ($this->deferCounters) {
            $this->dirtyEntities[$entityId] = true;

            return;
        }
        $factIds = Fact::where('entity_id', $entityId)->pluck('id');
        Entity::whereKey($entityId)->update([
            'facts_count' => $factIds->count(),
            'sources_count' => FactSource::whereIn('fact_id', $factIds)->distinct()->count('source_id'),
        ]);
    }
}
