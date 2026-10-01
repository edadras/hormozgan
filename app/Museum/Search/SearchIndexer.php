<?php

namespace App\Museum\Search;

use App\Models\Museum\DocumentPage;
use App\Models\Museum\Entity;
use App\Models\Museum\Fact;
use App\Models\Museum\InterviewSegment;
use App\Models\Museum\SearchDocument;
use App\Models\Museum\Sentence;
use App\Models\Museum\TextChunk;
use App\Museum\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds denormalized search documents (all names, historical names, public facts) and the
 * entity "profile" chunk used for semantic retrieval.
 */
class SearchIndexer
{
    public function __construct(private SearchEngine $engine) {}

    public function indexEntity(Entity $e): ?SearchDocument
    {
        if ($e->merged_into_id || $e->trashed()) {
            $this->remove($e);

            return null;
        }
        $e->loadMissing(['type', 'aliases', 'historicalNames', 'place']);
        $aliases = $e->aliases->pluck('normalized_alias')
            ->merge($e->historicalNames->pluck('normalized_name'))->unique()->implode(' ');
        $compact = $e->aliases->pluck('compact_key')->merge($e->historicalNames->map(fn ($h) => TextNormalizer::compact($h->name)))
            ->unique()->implode(' ');

        $facts = Fact::where('entity_id', $e->id)->public()->with(['property', 'valueEntity', 'values'])->limit(200)->get();
        $factLines = $facts->map(fn (Fact $f) => $f->property->label_fa.': '.$f->displayValue('fa'))->all();
        $extra = [];
        if ($e->type->key === 'word' && $e->word) {
            $extra[] = $e->word->headword.' '.$e->word->transcription_fa.' '.$e->word->meanings()->pluck('meaning_fa')->implode(' ');
        }
        if ($e->type->key === 'proverb' && $e->proverb) {
            $extra[] = $e->proverb->text_local.' '.$e->proverb->figurative_meaning.' '.$e->proverb->persian_equivalent;
        }
        if ($e->place && $e->place->parent_id) {
            $extra[] = Entity::find($e->place->parent_id)?->displayName();
        }
        $body = trim(implode("\n", array_filter(array_merge([$e->summary_fa, $e->summary_en], $extra, $factLines))));

        [$yFrom, $yTo] = $this->years($e);
        $doc = SearchDocument::updateOrCreate(
            ['searchable_type' => $e->getMorphClass(), 'searchable_id' => $e->id],
            [
                'doc_type' => $e->type->key,
                'title' => $e->displayName(),
                'aliases' => $aliases,
                'body' => $body,
                'normalized_text' => TextNormalizer::normalize($e->displayName().' '.$aliases.' '.$body),
                'compact_text' => $compact,
                'url' => '/museum/e/'.$e->slug,
                'place_id' => $e->place ? $e->id : $e->primary_place_id,
                'year_from' => $yFrom,
                'year_to' => $yTo,
                'verification_status' => $e->verification_status,
                'is_public' => $e->isPublished(),
                'boost' => $this->boost($e),
            ]
        );
        $this->engine->index($doc);
        $this->profileChunk($e, $facts->all());

        return $doc;
    }

    public function indexSentence(Sentence $s): SearchDocument
    {
        $doc = SearchDocument::updateOrCreate(
            ['searchable_type' => $s->getMorphClass(), 'searchable_id' => $s->id],
            [
                'doc_type' => 'sentence',
                'title' => mb_substr($s->text_local, 0, 500),
                'aliases' => null,
                'body' => trim($s->text_fa.' '.$s->text_en.' '.$s->transcription_fa),
                'normalized_text' => TextNormalizer::normalize($s->text_local.' '.$s->text_fa.' '.$s->text_en.' '.$s->transcription_fa),
                'compact_text' => null,
                'url' => '/museum/language/sentences/'.$s->uuid,
                'place_id' => $s->place_id,
                'verification_status' => $s->verification_status,
                'is_public' => $s->visibility === 'published',
                'boost' => 0.9,
            ]
        );
        $this->engine->index($doc);

        return $doc;
    }

    public function indexDocumentPage(DocumentPage $p): ?SearchDocument
    {
        $text = $p->bestText();
        $doc = $p->document;
        if (! $text || ! $doc) {
            return null;
        }
        $entity = $doc->entity;
        $sd = SearchDocument::updateOrCreate(
            ['searchable_type' => $p->getMorphClass(), 'searchable_id' => $p->id],
            [
                'doc_type' => 'document_page',
                'title' => $entity->displayName().' — ص '.$p->page_number,
                'body' => mb_substr($text, 0, 60000),
                'normalized_text' => TextNormalizer::normalize($entity->displayName().' '.$text),
                'url' => '/museum/e/'.$entity->slug.'?page='.$p->page_number,
                'year_from' => $doc->year,
                'year_to' => $doc->year,
                'verification_status' => $p->status === 'verified' ? 'source_verified' : 'unverified',
                'is_public' => $entity->isPublished(),
                'boost' => 0.8,
            ]
        );
        $this->engine->index($sd);

        return $sd;
    }

    public function indexSegment(InterviewSegment $s): ?SearchDocument
    {
        $interview = $s->interview;
        $entity = $interview?->entity;
        if (! $entity) {
            return null;
        }
        $text = $s->text_corrected ?? $s->text_original;
        $consented = $interview->speaker ? $interview->speaker->hasConsentFor('publish_transcript') : false;
        $sd = SearchDocument::updateOrCreate(
            ['searchable_type' => $s->getMorphClass(), 'searchable_id' => $s->id],
            [
                'doc_type' => 'interview_segment',
                'title' => $entity->displayName().' ['.gmdate('H:i:s', intdiv($s->start_ms, 1000)).']',
                'body' => $text.($s->text_fa ? "\n".$s->text_fa : ''),
                'normalized_text' => TextNormalizer::normalize($text.' '.$s->text_fa),
                'url' => '/museum/e/'.$entity->slug.'?t='.intdiv($s->start_ms, 1000),
                'place_id' => $interview->place_id,
                'verification_status' => $s->verification_status,
                'is_public' => $entity->isPublished() && $consented,
                'boost' => 0.85,
            ]
        );
        $this->engine->index($sd);

        return $sd;
    }

    public function remove(Model $m): void
    {
        $doc = SearchDocument::where('searchable_type', $m->getMorphClass())->where('searchable_id', $m->getKey())->first();
        if ($doc) {
            $this->engine->remove($doc);
            $doc->delete();
        }
    }

    /** A compact textual profile of the entity used for semantic (vector) retrieval. */
    private function profileChunk(Entity $e, array $facts): void
    {
        $lines = [$e->displayName().' ('.$e->type->name_fa.')'];
        if ($e->name_en) {
            $lines[] = $e->name_en;
        }
        foreach ($facts as $f) {
            $lines[] = $f->property->label_fa.': '.$f->displayValue('fa');
        }
        $text = implode("\n", $lines);
        $hash = hash('sha256', $text);
        $existing = TextChunk::where('chunkable_type', $e->getMorphClass())->where('chunkable_id', $e->id)->first();
        if ($existing && $existing->text_hash === $hash && $existing->is_public === $e->isPublished()) {
            return;
        }
        TextChunk::updateOrCreate(
            ['chunkable_type' => $e->getMorphClass(), 'chunkable_id' => $e->id],
            ['entity_id' => $e->id, 'seq' => 0, 'text' => $text, 'normalized_text' => TextNormalizer::normalize($text),
                'token_estimate' => (int) ceil(mb_strlen($text) / 3.5), 'text_hash' => $hash, 'is_public' => $e->isPublished()]
        );
    }

    private function years(Entity $e): array
    {
        return match ($e->type->key) {
            'historical_event' => [$e->historicalEvent?->year_from, $e->historicalEvent?->year_to ?? $e->historicalEvent?->year_from],
            'person' => [$e->person?->birth_year, $e->person?->death_year],
            default => [null, null],
        };
    }

    private function boost(Entity $e): float
    {
        $b = match ($e->type->key) {
            'province', 'county', 'city' => 1.6,
            'district', 'island', 'port' => 1.3,
            'village', 'rural_district' => 1.0,
            'mountain', 'natural_feature' => 0.8,
            default => 1.1,
        };

        return $b + min(0.5, ($e->facts_count ?? 0) / 40);
    }
}
