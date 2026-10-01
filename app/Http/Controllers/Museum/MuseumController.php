<?php

namespace App\Http\Controllers\Museum;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Museum\Api\ExploreController;
use App\Models\Museum\Concept;
use App\Models\Museum\Diagram;
use App\Models\Museum\Entity;
use App\Models\Museum\EntityType;
use App\Models\Museum\Fact;
use App\Models\Museum\GrammarRule;
use App\Models\Museum\HistoricalName;
use App\Models\Museum\Media;
use App\Models\Museum\PhotoPair;
use App\Models\Museum\Sentence;
use App\Models\Museum\Source;
use App\Museum\Enums\VerificationStatus;
use App\Museum\Search\RagService;
use App\Museum\Search\SearchEngine;
use App\Museum\Services\QualityMetrics;
use App\Museum\Services\SubmissionService;
use App\Museum\Support\CacheVersion;
use App\Museum\Support\EntityPresenter;
use App\Museum\Support\TextNormalizer;
use Illuminate\Http\Request;

class MuseumController extends Controller
{
    /** Museum wings: title, intro and the entity types they exhibit. */
    public const SECTIONS = [
        'places' => ['مکان‌ها', 'شهرستان‌ها، بخش‌ها، شهرها، روستاها، جزایر و مکان‌های تاریخی هرمزگان.', ['province', 'county', 'district', 'city', 'rural_district', 'village', 'neighborhood', 'island', 'port', 'historical_site', 'building', 'market', 'mountain', 'river', 'bay', 'natural_feature'], '🗺️'],
        'language' => ['زبان و گویش‌ها', 'اطلس گویش‌های هرمزگان: واژه‌ها، تلفظ‌های بومی، دستور زبان، جمله‌ها و ضرب‌المثل‌ها.', ['dialect', 'word', 'proverb'], '🗣️'],
        'history' => ['تاریخ', 'رویدادها، نام‌های تاریخی، اسناد و نقشه‌ها.', ['historical_event', 'historical_site', 'document'], '📜'],
        'sea' => ['دریا', 'لنج، صیادی، غواصی مروارید، ناوبری، بنادر و تجارت دریایی.', ['boat_type', 'ship', 'boat_part', 'sea_route', 'fishing_tool', 'maritime_practice', 'port'], '⛵'],
        'food' => ['خوراک', 'غذاهای دریایی، نان‌ها، شیرینی‌ها، ترشی‌ها و ادویه‌ها؛ با تفاوت‌های منطقه‌ای.', ['food', 'local_product'], '🍲'],
        'people' => ['مردم', 'شخصیت‌ها، طوایف، مشاغل و تاریخ شفاهی.', ['person', 'social_group', 'occupation', 'interview'], '👥'],
        'nature' => ['طبیعت و کشاورزی', 'گیاهان بومی، ارقام خرما، جانوران و آبزیان.', ['plant', 'plant_variety', 'animal', 'mountain', 'river', 'natural_feature'], '🌴'],
        'culture' => ['فرهنگ', 'آیین‌ها، پوشاک، صنایع دستی، بازی‌ها و موسیقی.', ['tradition', 'clothing_item', 'craft', 'game', 'music_instrument', 'music_work', 'music_genre', 'story', 'poem'], '🧵'],
        'archive' => ['آرشیو', 'اسناد، نقشه‌ها، عکس‌های تاریخی و مصاحبه‌ها.', ['document', 'interview'], '🗄️'],
    ];

    public function __construct(private EntityPresenter $presenter) {}

    public function home()
    {
        $data = CacheVersion::remember('web:home', 300, function () {
            $counts = [];
            foreach (self::SECTIONS as $key => [, , $types]) {
                $counts[$key] = Entity::published()->whereIn('entity_type_id', EntityType::idsFor($types))->count();
            }

            return ['counts' => $counts, 'stats' => app(QualityMetrics::class)->publicCounts()];
        });

        return view('museum.home', $data + ['sections' => self::SECTIONS]);
    }

    public function section(Request $r, string $section)
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        [$title, $intro, $types, $icon] = self::SECTIONS[$section];
        $r->validate(['type' => 'nullable|string|max:64', 'q' => 'nullable|string|max:200']);
        $active = in_array($r->query('type'), $types, true) ? [$r->query('type')] : $types;
        $q = Entity::published()->with('type')->whereIn('entity_type_id', EntityType::idsFor($active));
        if ($term = $r->query('q')) {
            $q->where('normalized_name', 'like', '%'.TextNormalizer::normalize($term).'%');
        }
        $entities = $q->orderByDesc('facts_count')->orderBy('canonical_name')->paginate(48)->withQueryString();
        $typeCounts = Entity::published()->whereIn('entity_type_id', EntityType::idsFor($types))
            ->selectRaw('entity_type_id, count(*) n')->groupBy('entity_type_id')->pluck('n', 'entity_type_id');
        $typeList = EntityType::whereIn('key', $types)->get()->map(fn ($t) => ['key' => $t->key, 'name' => $t->name_fa, 'n' => (int) ($typeCounts[$t->id] ?? 0)]);

        return view('museum.section', compact('section', 'title', 'intro', 'types', 'icon', 'entities', 'typeList') + ['activeType' => $r->query('type')]);
    }

    public function entity(Request $r, string $slug)
    {
        $e = Entity::with('type')->where('slug', $slug)->firstOrFail();
        if ($e->merged_into_id) {
            return redirect()->to(Entity::findOrFail($e->merged_into_id)->publicUrl(), 301);
        }
        abort_unless($e->isPublished() || $r->user()?->can('museum-admin-panel'), 404);
        $d = $this->presenter->detail($e);

        return view('museum.entity', ['e' => $e, 'd' => $d, 'preview' => ! $e->isPublished()]);
    }

    public function fact(string $uuid)
    {
        $fact = Fact::where('uuid', $uuid)->firstOrFail();
        abort_unless($fact->entity?->isPublished() && Fact::public()->whereKey($fact->id)->exists(), 404);

        return view('museum.fact', ['p' => $this->presenter->provenance($fact)]);
    }

    public function source(string $uuid)
    {
        $s = Source::where('uuid', $uuid)->firstOrFail();
        $facts = Fact::public()->whereIn('id', fn ($q) => $q->select('fact_id')->from('museum_fact_sources')->where('source_id', $s->id))
            ->whereHas('entity', fn ($q) => $q->published())->with(['entity', 'property', 'values', 'valueEntity'])->latest('id')->paginate(50);

        return view('museum.source', ['s' => $s, 'facts' => $facts]);
    }

    public function search(Request $r, SearchEngine $engine)
    {
        $r->validate(['q' => 'nullable|string|max:200', 'type' => 'nullable|string|max:64']);
        $q = trim((string) $r->query('q'));
        $res = $q !== '' ? $engine->search($q, ['types' => array_filter([$r->query('type')]), 'public_only' => true], max(1, $r->integer('page', 1)), 30) : ['total' => 0, 'hits' => []];

        return view('museum.search', ['q' => $q, 'res' => $res, 'page' => max(1, $r->integer('page', 1))]);
    }

    public function ask(Request $r, RagService $rag)
    {
        $result = null;
        if ($r->isMethod('post')) {
            $data = $r->validate(['question' => 'required|string|min:3|max:1000']);
            $result = $rag->ask($data['question'], $r->user()?->id, $r->ip());
        }

        return view('museum.ask', ['result' => $result, 'question' => $r->input('question')]);
    }

    public function explore(ExploreController $explore)
    {
        return view('museum.explore', ['items' => $explore->pick()]);
    }

    public function language(Request $r)
    {
        $concepts = Concept::whereHas('words', fn ($q) => $q->whereIn('entity_id', Entity::published()->select('id')))->orderBy('gloss_fa')->get();
        $dialects = Entity::published()->ofType('dialect')->orderBy('canonical_name')->get();
        $grammarTopics = GrammarRule::where('visibility', 'published')->selectRaw('topic, count(*) n')->groupBy('topic')->pluck('n', 'topic');
        $sentences = Sentence::where('visibility', 'published')->with('category')->latest('id')->limit(30)->get();
        $proverbs = Entity::published()->ofType('proverb')->with('proverb')->latest('id')->limit(20)->get();

        return view('museum.language', compact('concepts', 'dialects', 'grammarTopics', 'sentences', 'proverbs'));
    }

    public function compare(Request $r)
    {
        $concept = $r->filled('concept') ? Concept::where('key', $r->query('concept'))->first() : null;
        $concepts = Concept::whereHas('words', fn ($q) => $q->whereIn('entity_id', Entity::published()->select('id')))->orderBy('gloss_fa')->get();

        return view('museum.compare', compact('concept', 'concepts'));
    }

    public function grammar(Request $r)
    {
        $rules = GrammarRule::where('visibility', 'published')->with(['dialect', 'examples', 'citations.source'])
            ->when($r->query('topic'), fn ($q, $t) => $q->where('topic', $t))->orderBy('topic')->orderBy('sort')->get();

        return view('museum.grammar', ['rules' => $rules, 'topic' => $r->query('topic')]);
    }

    public function history()
    {
        $events = Entity::published()->ofType('historical_event')->join('museum_historical_events as he', 'he.entity_id', '=', 'museum_entities.id')
            ->select('museum_entities.*', 'he.year_from', 'he.year_to', 'he.event_type')->orderBy('he.year_from')->limit(500)->get();
        $names = HistoricalName::with('entity')->whereIn('verification_status', VerificationStatus::atLeastValues(VerificationStatus::SourceVerified))
            ->whereIn('entity_id', Entity::published()->select('id'))->orderBy('year_from')->limit(200)->get();
        $pairs = PhotoPair::where('visibility', 'published')->with(['thenMedia', 'nowMedia', 'place'])->limit(12)->get()
            ->filter(fn ($p) => $p->thenMedia?->isPubliclyServable() && $p->nowMedia?->isPubliclyServable());

        return view('museum.history', compact('events', 'names', 'pairs'));
    }

    public function timeMap()
    {
        return view('museum.timemap');
    }

    public function lenj()
    {
        $diagram = Diagram::where('visibility', 'published')->whereHas('entity', fn ($q) => $q->published()->ofType('boat_type'))
            ->with(['image', 'hotspots.part', 'entity'])->first();
        $parts = $diagram ? $diagram->hotspots->filter(fn ($h) => $h->part?->isPublished())
            ->map(fn ($h) => ['id' => $h->id, 'coords' => $h->coordinates, 'shape' => $h->shape, 'part' => $this->presenter->detail($h->part)])->values() : collect();

        return view('museum.lenj', compact('diagram', 'parts'));
    }

    public function contribute()
    {
        return view('museum.contribute', ['types' => SubmissionService::TYPES]);
    }

    public function contributeStore(Request $r, SubmissionService $service)
    {
        $data = $r->validate([
            'submission_type' => 'required|in:'.implode(',', SubmissionService::TYPES),
            'title' => 'nullable|string|max:300',
            'text' => 'required|string|max:20000',
            'meaning' => 'nullable|string|max:5000',
            'place_text' => 'nullable|string|max:200',
            'dialect_text' => 'nullable|string|max:200',
            'submitter_name' => 'nullable|string|max:200',
            'submitter_contact' => 'nullable|string|max:300',
            'consent_publish' => 'accepted',
            'is_own_work' => 'nullable|boolean',
            'website' => 'prohibited',
        ]);
        $payload = ['text' => $data['text']];
        if ($data['submission_type'] === 'word') {
            $payload = ['word' => $data['text'], 'meaning_fa' => $data['meaning'] ?? null];
        } elseif ($data['submission_type'] === 'old_name') {
            $payload = ['name' => $data['text'], 'meaning' => $data['meaning'] ?? null];
        } elseif (! empty($data['meaning'])) {
            $payload['meaning'] = $data['meaning'];
        }
        $s = $service->submit($data + ['payload' => $payload], $r->user()?->id, $r->ip());
        \App\Museum\Jobs\ProcessSubmissionJob::dispatch($s->id);

        return redirect()->route('museum.contribute')->with('status', 'سپاسگزاریم! مشارکت شما با کد '.$s->uuid.' ثبت شد و پس از بررسی منتشر می‌شود.');
    }

    public function kids()
    {
        $items = app(ExploreController::class)->pick();

        return view('museum.kids', ['items' => $items]);
    }

    public function crawlerInfo()
    {
        return view('museum.crawler');
    }

    /** Serves a media file only if the copyright/consent gate allows it. */
    public function file(Request $r, string $uuid)
    {
        $m = Media::where('uuid', $uuid)->firstOrFail();
        abort_unless($m->isPubliclyServable(), 403);
        $path = $r->query('v') && isset($m->variants[$r->query('v')]) ? $m->variants[$r->query('v')] : $m->path;

        return \Illuminate\Support\Facades\Storage::disk($m->disk)->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
