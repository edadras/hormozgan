<?php

namespace App\Http\Controllers\Museum\Api;

use App\Models\Museum\Concept;
use App\Models\Museum\Entity;
use App\Models\Museum\Word;
use Illuminate\Http\Request;

class LanguageController extends EntityController
{
    public function words(Request $r)
    {
        $r->validate(['dialect' => 'nullable|string|max:191', 'concept' => 'nullable|string|max:96', 'q' => 'nullable|string|max:200']);

        return $this->cached($r, function () use ($r) {
            $q = $this->baseQuery($r, ['word'])->with('word.meanings');
            if ($d = $r->query('dialect')) {
                $q->whereIn('id', fn ($s) => $s->select('w.entity_id')->from('museum_words as w')->join('museum_entities as de', 'de.id', '=', 'w.dialect_id')->where('de.slug', $d));
            }
            if ($c = $r->query('concept')) {
                $q->whereIn('id', fn ($s) => $s->select('w.entity_id')->from('museum_words as w')->join('museum_concepts as c', 'c.id', '=', 'w.concept_id')->where('c.key', $c));
            }
            $p = $q->orderBy('canonical_name')->paginate($this->perPage($r))->withQueryString();

            return $this->paginated($p, fn ($e) => $this->presenter->summary($e) + [
                'meaning_fa' => $e->word?->meanings->firstWhere('is_primary', true)?->meaning_fa,
                'transcription_fa' => $e->word?->transcription_fa,
            ]);
        });
    }

    public function dialects(Request $r)
    {
        return $this->cached($r, fn () => ['data' => $this->baseQuery($r, ['dialect'])->orderBy('canonical_name')->get()
            ->map(fn ($e) => $this->presenter->summary($e))->values()]);
    }

    /**
     * Dialect comparison for one concept (section 39): only documented, published words;
     * each row carries its place (for the map) and any public native recordings.
     */
    public function compare(Request $r)
    {
        $r->validate(['concept' => 'required|string|max:96']);

        return $this->cached($r, function () use ($r) {
            $concept = Concept::where('key', $r->query('concept'))->firstOrFail();
            $rows = Word::where('concept_id', $concept->id)
                ->whereIn('entity_id', Entity::published()->select('id'))
                ->with(['entity', 'dialect', 'place', 'pronunciations.recording.media', 'pronunciations.speaker', 'citations.source'])
                ->get()
                ->map(fn (Word $w) => [
                    'word' => $w->headword,
                    'transcription_fa' => $w->transcription_fa,
                    'ipa' => $w->ipa,
                    'dialect' => $w->dialect?->displayName(),
                    'place' => $w->place ? ['name' => $w->place->displayName(), 'lat' => $w->place->latitude, 'lng' => $w->place->longitude, 'slug' => $w->place->slug] : null,
                    'audio' => $w->pronunciations->filter(fn ($p) => $p->recording?->media?->isPubliclyServable())
                        ->map(fn ($p) => ['url' => $p->recording->media->publicUrl(), 'speaker' => $p->speaker?->publicName()])->values(),
                    'sources' => $w->citations->map(fn ($c) => $c->source->citationLabel().($c->page_number ? ', p. '.$c->page_number : ''))->values(),
                    'url' => $w->entity->publicUrl(),
                ]);

            return ['concept' => ['key' => $concept->key, 'fa' => $concept->gloss_fa, 'en' => $concept->gloss_en], 'data' => $rows];
        });
    }
}
