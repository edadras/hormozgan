<?php

namespace App\Museum\Support;

use App\Models\Museum\DocumentPage;
use App\Models\Museum\Entity;
use App\Models\Museum\EntityRelationship;
use App\Models\Museum\Fact;
use App\Models\Museum\FactSource;
use App\Models\Museum\InterviewSegment;
use App\Models\Museum\Media;
use App\Models\Museum\Mention;
use App\Models\Museum\Place;
use App\Models\Museum\Source;
use App\Museum\Enums\VerificationStatus;

/**
 * Converts knowledge records into public arrays (API + views). Only public material leaves
 * here: published entities, facts at or above the publication threshold with a source,
 * publicly servable media, consented speakers.
 */
class EntityPresenter
{
    public function summary(Entity $e, string $locale = 'fa'): array
    {
        return [
            'id' => $e->id,
            'uuid' => $e->uuid,
            'slug' => $e->slug,
            'type' => $e->type?->key,
            'type_label' => $locale === 'fa' ? $e->type?->name_fa : $e->type?->name_en,
            'name' => $e->displayName($locale),
            'name_fa' => $e->name_fa,
            'name_en' => $e->name_en,
            'name_local' => $e->name_local,
            'latitude' => $e->latitude,
            'longitude' => $e->longitude,
            'verification_status' => $e->verification_status,
            'facts_count' => $e->facts_count,
            'url' => $e->publicUrl(),
            'image' => $this->primaryImage($e),
        ];
    }

    /** Thumbnail of the primary public image, if any (with the original as fallback). */
    public function primaryImage(Entity $e): ?array
    {
        $m = $e->media()->public()->where('media_type', 'image')->orderByRaw("CASE WHEN museum_mediables.role = 'primary' THEN 0 ELSE 1 END")->first();

        return $m ? ['thumb' => $m->publicUrl('thumb') ?? $m->publicUrl(), 'url' => $m->publicUrl(), 'credit' => trim(($m->creator ? $m->creator.' · ' : '').$m->licenseEnum()->label())] : null;
    }

    public function detail(Entity $e, string $locale = 'fa'): array
    {
        $e->loadMissing(['type', 'aliases', 'historicalNames.source', 'place']);
        $facts = Fact::where('entity_id', $e->id)->public()
            ->with(['property', 'valueEntity', 'values', 'scopePlace', 'conflict', 'sources.source'])
            ->orderBy('property_id')->get();

        $groups = [];
        foreach ($facts as $f) {
            $g = $f->property->group ?? 'general';
            $groups[$g][] = $this->fact($f, $locale);
        }

        $relations = EntityRelationship::with(['type', 'object.type', 'subject.type'])
            ->where(fn ($q) => $q->where('subject_id', $e->id)->orWhere('object_id', $e->id))
            ->whereIn('verification_status', VerificationStatus::atLeastValues(VerificationStatus::from(config('museum.publication.min_fact_status'))))
            ->limit(300)->get()
            ->map(function (EntityRelationship $r) use ($e, $locale) {
                $outgoing = $r->subject_id === $e->id;
                $other = $outgoing ? $r->object : $r->subject;
                if (! $other || ! $other->isPublished()) {
                    return null;
                }

                return [
                    'relation' => $outgoing ? $r->type->key : ($r->type->inverse_key ?? $r->type->key),
                    'label' => $outgoing ? ($locale === 'fa' ? $r->type->name_fa : $r->type->name_en)
                        : ($locale === 'fa' ? $r->type->inverse_name_fa : $r->type->inverse_name_en),
                    'entity' => $this->summary($other, $locale),
                    'fact_uuid' => $r->fact?->uuid,
                ];
            })->filter()->values();

        $children = [];
        if ($e->place) {
            $children = Place::where('parent_id', $e->id)->with('entity.type')->limit(500)->get()
                ->map(fn ($p) => $p->entity && $p->entity->isPublished() ? $this->summary($p->entity, $locale) : null)
                ->filter()->sortBy('name')->values()->all();
        }

        return $this->summary($e, $locale) + [
            'summary' => $locale === 'fa' ? $e->summary_fa : $e->summary_en,
            'aliases' => $e->aliases->map(fn ($a) => ['alias' => $a->alias, 'language' => $a->language, 'type' => $a->alias_type])->values(),
            'historical_names' => $e->historicalNames
                ->filter(fn ($h) => VerificationStatus::tryFrom($h->verification_status)?->atLeast(VerificationStatus::SourceVerified))
                ->map(fn ($h) => [
                    'name' => $h->name, 'local_pronunciation' => $h->local_pronunciation, 'period' => $h->period_label,
                    'year_from' => $h->year_from, 'year_to' => $h->year_to, 'meaning' => $h->meaning, 'reason' => $h->naming_reason,
                    'source' => $h->source ? $this->source($h->source) : null, 'page' => $h->page_number,
                ])->values(),
            'facts' => $groups,
            'relations' => $relations,
            'breadcrumbs' => $this->breadcrumbs($e, $locale),
            'children' => $children,
            'media' => $this->media($e),
            'mentions' => $this->mentions($e),
            'extension' => $this->extension($e),
        ];
    }

    public function fact(Fact $f, string $locale = 'fa'): array
    {
        return [
            'uuid' => $f->uuid,
            'property' => $f->property->key,
            'label' => $locale === 'fa' ? $f->property->label_fa : $f->property->label_en,
            'value' => $f->displayValue($locale),
            'unit' => $f->property->unit,
            'is_unknown' => $f->is_unknown,
            'value_entity' => $f->valueEntity && $f->valueEntity->isPublished() ? ['slug' => $f->valueEntity->slug, 'name' => $f->valueEntity->displayName($locale)] : null,
            'qualifiers' => $f->qualifiers,
            'scope_place' => $f->scopePlace?->displayName($locale),
            'verification_status' => $f->verification_status,
            'verification_label' => VerificationStatus::from($f->verification_status)->label(),
            'confidence' => $f->confidence_score,
            'disputed' => $f->conflict_id !== null && $f->conflict?->status === 'open',
            'preferred' => $f->is_preferred,
            'sources' => $f->sources->map(fn (FactSource $fs) => [
                'source' => $this->source($fs->source),
                'page' => $fs->page_number,
                'locator' => $fs->locator,
                'quote' => $fs->quote,
            ])->values(),
            'provenance_url' => url('/museum/facts/'.$f->uuid),
        ];
    }

    public function source(Source $s): array
    {
        return [
            'uuid' => $s->uuid,
            'type' => $s->source_type,
            'title' => $s->title,
            'author' => $s->author,
            'publisher' => $s->publisher,
            'date' => $s->publication_date,
            'url' => $s->url,
            'doi' => $s->doi,
            'isbn' => $s->isbn,
            'license' => $s->license,
            'reliability_tier' => $s->reliability_tier,
            'citation' => $s->citationLabel(),
            'short' => $s->shortLabel(),
            'page_url' => url('/museum/sources/'.$s->uuid),
        ];
    }

    /** Full chain for one fact: Fact → Source → Page → Extract → raw document. */
    public function provenance(Fact $f): array
    {
        $f->loadMissing(['entity.type', 'property', 'revisions', 'sources.source', 'sources.extract.rawDocument', 'sources.extract.documentPage', 'conflict.facts.sources.source']);

        return [
            'fact' => $this->fact($f),
            'entity' => $this->summary($f->entity),
            'evidence' => $f->sources->map(fn (FactSource $fs) => [
                'source' => $this->source($fs->source),
                'page' => $fs->page_number,
                'locator' => $fs->locator,
                'quote' => $fs->quote,
                'extract' => $fs->extract ? [
                    'original_text' => $fs->extract->original_text,
                    'page' => $fs->extract->page_number,
                    'extracted_by' => $fs->extract->extracted_by,
                    'raw_document' => $fs->extract->rawDocument ? [
                        'url' => $fs->extract->rawDocument->url,
                        'sha256' => $fs->extract->rawDocument->sha256,
                        'fetched_at' => $fs->extract->rawDocument->fetched_at?->toIso8601String(),
                    ] : null,
                ] : null,
            ])->values(),
            'revisions' => $f->revisions->map(fn ($r) => [
                'revision' => $r->revision, 'change' => $r->change_type, 'previous' => $r->previous_value, 'new' => $r->new_value,
                'previous_status' => $r->previous_status, 'new_status' => $r->new_status, 'reason' => $r->reason,
                'at' => $r->created_at?->toIso8601String(),
            ])->values(),
            'conflict' => $f->conflict ? [
                'status' => $f->conflict->status,
                'resolution' => $f->conflict->resolution,
                'note' => $f->conflict->resolution_note,
                'alternatives' => $f->conflict->facts->where('id', '!=', $f->id)->map(fn ($o) => [
                    'uuid' => $o->uuid, 'value' => $o->displayValue(), 'status' => $o->verification_status,
                    'sources' => $o->sources->map(fn ($s) => $s->source->citationLabel())->values(),
                ])->values(),
            ] : null,
        ];
    }

    public function breadcrumbs(Entity $e, string $locale = 'fa'): array
    {
        $path = $e->place?->path;
        if (! $path) {
            return [];
        }
        $ids = array_values(array_filter(explode('/', $path)));
        array_pop($ids);
        if (! $ids) {
            return [];
        }
        $ents = Entity::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => isset($ents[$id]) ? ['slug' => $ents[$id]->slug, 'name' => $ents[$id]->displayName($locale)] : null)
            ->filter()->values()->all();
    }

    public function media(Entity $e): array
    {
        return $e->media()->public()->get()->map(fn (Media $m) => [
            'uuid' => $m->uuid, 'type' => $m->media_type, 'title' => $m->title, 'description' => $m->description,
            'creator' => $m->creator, 'year' => $m->year, 'year_precision' => $m->year_precision, 'license' => $m->licenseEnum()->label(),
            'url' => $m->publicUrl(), 'thumb' => $m->publicUrl('thumb'), 'role' => $m->pivot->role,
            'source' => $m->source ? $m->source->citationLabel() : null,
        ])->filter(fn ($m) => $m['url'])->values()->all();
    }

    /** Interview segments / document pages that mention this entity (confirmed or high-confidence). */
    public function mentions(Entity $e): array
    {
        return Mention::where('entity_id', $e->id)
            ->where(fn ($q) => $q->where('status', 'confirmed')->orWhere(fn ($w) => $w->where('status', 'suggested')->where('confidence_score', '>=', 0.8)))
            ->with('mentionable')->limit(50)->get()
            ->map(function (Mention $m) {
                $t = $m->mentionable;
                if ($t instanceof InterviewSegment) {
                    $iv = $t->interview;
                    if (! $iv || ! $iv->entity?->isPublished() || ! $iv->speaker?->hasConsentFor('publish_transcript')) {
                        return null;
                    }

                    return ['kind' => 'interview', 'title' => $iv->entity->displayName(), 'url' => $iv->entity->publicUrl().'?t='.intdiv($t->start_ms, 1000),
                        'excerpt' => mb_substr($t->text_corrected ?? $t->text_original, 0, 240), 'at_ms' => $t->start_ms];
                }
                if ($t instanceof DocumentPage) {
                    $doc = $t->document?->entity;
                    if (! $doc || ! $doc->isPublished()) {
                        return null;
                    }

                    return ['kind' => 'document', 'title' => $doc->displayName().' — ص '.$t->page_number, 'url' => $doc->publicUrl().'?page='.$t->page_number,
                        'excerpt' => mb_substr((string) $t->bestText(), 0, 240)];
                }

                return null;
            })->filter()->values()->all();
    }

    private function extension(Entity $e): array
    {
        return match ($e->type?->key) {
            'word' => $e->word ? [
                'headword' => $e->word->headword, 'transcription_fa' => $e->word->transcription_fa, 'ipa' => $e->word->ipa,
                'part_of_speech' => $e->word->part_of_speech, 'dialect' => $e->word->dialect?->displayName(),
                'meanings' => $e->word->meanings->map(fn ($m) => ['fa' => $m->meaning_fa, 'en' => $m->meaning_en])->values(),
                'pronunciations' => $e->word->pronunciations()->with(['recording.media', 'speaker', 'place'])->get()
                    ->filter(fn ($p) => $p->recording?->media?->isPubliclyServable())
                    ->map(fn ($p) => ['audio' => $p->recording->media->publicUrl(), 'speaker' => $p->speaker?->publicName(),
                        'gender' => $p->speaker?->gender, 'age_group' => $p->speaker?->age_group, 'place' => $p->place?->displayName(), 'ipa' => $p->ipa])->values(),
                'examples' => $e->word->examples()->where('visibility', 'published')->get()->map(fn ($s) => ['local' => $s->text_local, 'fa' => $s->text_fa, 'en' => $s->text_en])->values(),
            ] : [],
            'proverb' => $e->proverb ? $e->proverb->only(['kind', 'text_local', 'transcription_fa', 'literal_meaning', 'figurative_meaning', 'usage_context', 'backstory', 'persian_equivalent']) : [],
            'person' => $e->person && $e->person->privacy_level === 'public_figure' ? $e->person->only(['birth_year', 'death_year', 'date_precision']) : [],
            'historical_event' => $e->historicalEvent ? $e->historicalEvent->only(['event_type', 'year_from', 'year_to', 'date_precision', 'date_original']) : [],
            'plant', 'plant_variety' => array_filter(['scientific_name' => $e->plant?->scientific_name, 'family' => $e->plant?->family,
                'varieties' => $e->plant ? $e->plant->varieties()->with('entity')->get()->filter(fn ($v) => $v->entity?->isPublished())
                    ->map(fn ($v) => $this->summary($v->entity))->values() : null]),
            default => $e->place ? ['place_type' => $e->place->place_type, 'is_historical' => $e->place->is_historical] : [],
        };
    }
}
