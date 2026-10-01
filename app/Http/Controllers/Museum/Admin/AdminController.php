<?php

namespace App\Http\Controllers\Museum\Admin;

use App\Http\Controllers\Controller;
use App\Models\Museum\AiJob;
use App\Models\Museum\CommunitySubmission;
use App\Models\Museum\CrawlerSource;
use App\Models\Museum\DuplicateCandidate;
use App\Models\Museum\Entity;
use App\Models\Museum\EntityType;
use App\Models\Museum\ExtractionCandidate;
use App\Models\Museum\Fact;
use App\Models\Museum\FactConflict;
use App\Models\Museum\ImportBatch;
use App\Models\Museum\Media;
use App\Models\Museum\Property;
use App\Models\Museum\ResearchTask;
use App\Models\Museum\Source;
use App\Models\Museum\Speaker;
use App\Museum\Enums\License;
use App\Museum\Enums\SubmissionStatus;
use App\Museum\Enums\VerificationStatus;
use App\Museum\Jobs\FetchCrawlerSourceJob;
use App\Museum\Jobs\ProcessMediaJob;
use App\Museum\Jobs\ReindexEntityJob;
use App\Museum\Jobs\RunResearchTaskJob;
use App\Museum\Services\CandidateProcessor;
use App\Museum\Services\EntityService;
use App\Museum\Services\FactService;
use App\Museum\Services\MediaService;
use App\Museum\Services\MergeService;
use App\Museum\Services\QualityMetrics;
use App\Museum\Services\SourceService;
use App\Museum\Services\SubmissionService;
use App\Museum\Services\VerificationService;
use App\Museum\Support\CacheVersion;
use App\Museum\Support\TextNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Museum admin panel. Every mutating action checks a museum permission and is logged in
 * museum_verification_logs (via the services).
 */
class AdminController extends Controller
{
    private function can(string $permission): void
    {
        abort_unless(Gate::allows('museum', $permission), 403, 'Missing permission: '.$permission);
    }

    public function dashboard(QualityMetrics $metrics)
    {
        return view('museum.admin.dashboard', ['q' => $metrics->quality(), 'b' => $metrics->bigData(),
            'imports' => ImportBatch::latest('id')->limit(8)->get()]);
    }

    // ------------------------------------------------------------ verification queue
    public function verification(Request $r)
    {
        $status = in_array($r->query('status'), ['unverified', 'ai_extracted', 'source_verified', 'community_verified'], true) ? $r->query('status') : 'ai_extracted';
        $facts = Fact::where('verification_status', $status)
            ->with(['entity.type', 'property', 'valueEntity', 'values', 'sources.source', 'sources.extract'])
            ->when($r->query('low'), fn ($q) => $q->where('confidence_score', '<', config('museum.extraction.low_confidence_threshold')))
            ->orderBy('confidence_score')->orderBy('id')->paginate(25)->withQueryString();

        return view('museum.admin.verification', compact('facts', 'status'));
    }

    public function verifyFact(Request $r, Fact $fact, VerificationService $v)
    {
        $data = $r->validate(['status' => 'required|in:'.implode(',', array_column(VerificationStatus::cases(), 'value')), 'notes' => 'nullable|string|max:2000']);
        $v->setFactStatus($fact, VerificationStatus::from($data['status']), $r->user(), $data['notes'] ?? null);
        ReindexEntityJob::dispatch($fact->entity_id);

        return back()->with('status', 'وضعیت به‌روزرسانی شد.');
    }

    // ------------------------------------------------------------ conflicts
    public function conflicts(Request $r)
    {
        $conflicts = FactConflict::where('status', $r->query('status', 'open'))
            ->with(['entity', 'property', 'facts.sources.source', 'facts.values', 'facts.valueEntity'])->latest('id')->paginate(20)->withQueryString();

        return view('museum.admin.conflicts', compact('conflicts'));
    }

    public function resolveConflict(Request $r, FactConflict $conflict, FactService $facts)
    {
        $this->can('conflicts.resolve');
        $data = $r->validate(['preferred_fact_id' => 'nullable|integer', 'resolution' => 'required|in:preferred_selected,both_retained', 'note' => 'required|string|max:2000', 'reject_others' => 'boolean']);
        $facts->resolveConflict($conflict, $data['preferred_fact_id'] ?? null, $data['resolution'], $data['note'], $r->user()->id, (bool) ($data['reject_others'] ?? false));
        ReindexEntityJob::dispatch($conflict->entity_id);

        return back()->with('status', 'اختلاف بررسی شد.');
    }

    // ------------------------------------------------------------ duplicates
    public function duplicates()
    {
        $dups = DuplicateCandidate::where('status', 'pending')->with(['entityA.type', 'entityB.type'])->orderByDesc('score')->paginate(25);

        return view('museum.admin.duplicates', compact('dups'));
    }

    public function decideDuplicate(Request $r, DuplicateCandidate $dup, MergeService $merge)
    {
        $this->can('entities.merge');
        $data = $r->validate(['decision' => 'required|in:merge_a_into_b,merge_b_into_a,distinct', 'reason' => 'required|string|max:1000']);
        if ($data['decision'] === 'distinct') {
            $dup->update(['status' => 'distinct', 'reviewed_by' => $r->user()->id, 'reviewed_at' => now()]);
        } else {
            [$from, $into] = $data['decision'] === 'merge_a_into_b' ? [$dup->entityA, $dup->entityB] : [$dup->entityB, $dup->entityA];
            $merge->merge($from, $into, $r->user()->id, $data['reason']);
            ReindexEntityJob::dispatch($from->id);
            ReindexEntityJob::dispatch($into->id);
        }

        return back()->with('status', 'ثبت شد.');
    }

    // ------------------------------------------------------------ candidates (discovery)
    public function candidates(Request $r)
    {
        $status = in_array($r->query('status'), ['in_review', 'needs_evidence', 'pending', 'rejected', 'accepted'], true) ? $r->query('status') : 'in_review';
        $candidates = ExtractionCandidate::where('status', $status)->with(['source', 'matchedEntity', 'extract'])
            ->orderByDesc('evidence_count')->orderByDesc('confidence_score')->paginate(30)->withQueryString();

        return view('museum.admin.candidates', compact('candidates', 'status'));
    }

    public function decideCandidate(Request $r, ExtractionCandidate $candidate, CandidateProcessor $processor)
    {
        $this->can('candidates.review');
        $data = $r->validate(['decision' => 'required|in:create,link,reject', 'entity_slug' => 'nullable|string', 'reason' => 'nullable|string|max:1000']);
        if ($data['decision'] === 'reject') {
            $processor->reject($candidate, $r->user()->id, $data['reason'] ?: 'rejected by reviewer');
        } else {
            $link = $data['decision'] === 'link' ? Entity::where('slug', $data['entity_slug'])->firstOrFail() : null;
            $processor->acceptEntity($candidate, $link, $r->user()->id);
        }

        return back()->with('status', 'ثبت شد.');
    }

    // ------------------------------------------------------------ sources & crawler
    public function sources(Request $r)
    {
        $sources = Source::withCount('factSources')->when($r->query('q'), fn ($q, $t) => $q->where('title', 'like', '%'.$t.'%'))
            ->orderByDesc('priority')->orderBy('title')->paginate(40)->withQueryString();

        return view('museum.admin.sources', compact('sources'));
    }

    public function sourceEdit(Source $source)
    {
        return view('museum.admin.source-edit', ['s' => $source, 'crawlers' => $source->crawlerSources()->get()]);
    }

    public function sourceStore(Request $r, SourceService $sources)
    {
        $this->can('sources.manage');
        $data = $this->validateSource($r);
        $s = $sources->register($data, $r->user()->id);

        return redirect()->route('museum.admin.sources.edit', $s)->with('status', 'منبع ثبت شد.');
    }

    public function sourceUpdate(Request $r, Source $source, VerificationService $v)
    {
        $this->can('sources.manage');
        $data = $this->validateSource($r);
        $before = $source->only(['license', 'crawl_policy', 'verification_status']);
        $source->fill($data);
        if ($source->isDirty('verification_status')) {
            $source->reviewed_by = $r->user()->id;
            $source->reviewed_at = now();
        }
        $source->save();
        $v->log($source, 'source_update', json_encode($before), json_encode($source->only(array_keys($before))), $r->user()->id, $r->input('change_note'));

        return back()->with('status', 'ذخیره شد.');
    }

    private function validateSource(Request $r): array
    {
        return $r->validate([
            'source_type' => 'required|in:'.implode(',', Source::TYPES), 'title' => 'required|string|max:1000', 'author' => 'nullable|string|max:1000',
            'publisher' => 'nullable|string|max:255', 'publication_date' => 'nullable|string|max:32', 'url' => 'nullable|url|max:2048',
            'container_title' => 'nullable|string|max:1000', 'isbn' => 'nullable|string|max:32', 'doi' => 'nullable|string|max:255',
            'archive_reference' => 'nullable|string|max:255', 'language' => 'nullable|string|max:16',
            'license' => 'required|in:'.implode(',', array_column(License::cases(), 'value')),
            'copyright_status' => 'nullable|string|max:32', 'reliability_tier' => 'required|integer|between:1,5',
            'crawl_policy' => 'required|in:allowed,metadata_only,forbidden,pending_review',
            'verification_status' => 'required|in:unverified,source_verified,expert_verified',
            'phase' => 'nullable|in:A,B,C,D,E,F', 'priority' => 'nullable|integer|between:1,5', 'notes' => 'nullable|string|max:5000',
        ]);
    }

    public function crawlerUpdate(Request $r, CrawlerSource $crawler, VerificationService $v)
    {
        $this->can('pipeline.run');
        $data = $r->validate(['enabled' => 'boolean', 'terms_reviewed' => 'boolean', 'crawl_delay_ms' => 'integer|min:1000', 'max_documents' => 'integer|min:1|max:100000',
            'kind' => 'in:http_document,http_listing', 'allowed_patterns' => 'nullable|string', 'seed_urls' => 'nullable|string', 'terms_note' => 'nullable|string|max:2000']);
        if (! empty($data['terms_reviewed']) && ! $crawler->terms_reviewed) {
            $crawler->terms_reviewed_by = $r->user()->id;
            $v->log($crawler->source ?? $crawler, 'terms_reviewed', null, 'reviewed', $r->user()->id, $data['terms_note'] ?? null);
        }
        foreach (['allowed_patterns', 'seed_urls'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = array_values(array_filter(array_map('trim', preg_split('/\R/', $data[$k]))));
            }
        }
        unset($data['terms_note']);
        $crawler->fill($data)->save();
        if ($r->boolean('run_now') && $crawler->enabled && $crawler->terms_reviewed) {
            FetchCrawlerSourceJob::dispatch($crawler->id);
        }

        return back()->with('status', 'ذخیره شد.');
    }

    // ------------------------------------------------------------ imports / ai jobs
    public function imports()
    {
        return view('museum.admin.imports', ['batches' => ImportBatch::latest('id')->paginate(30)]);
    }

    public function importShow(ImportBatch $batch)
    {
        return view('museum.admin.import-show', ['b' => $batch]);
    }

    public function aiJobs(Request $r)
    {
        $jobs = AiJob::when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(40)->withQueryString();

        return view('museum.admin.ai-jobs', compact('jobs'));
    }

    // ------------------------------------------------------------ submissions
    public function submissions(Request $r)
    {
        $status = $r->query('status', 'ai_checked');
        $subs = CommunitySubmission::where('status', $status)->with('place')->latest('id')->paginate(25)->withQueryString();

        return view('museum.admin.submissions', compact('subs', 'status'));
    }

    public function submissionTransition(Request $r, CommunitySubmission $submission, SubmissionService $service)
    {
        $data = $r->validate(['to' => 'required|in:'.implode(',', array_column(SubmissionStatus::cases(), 'value')), 'notes' => 'nullable|string|max:2000', 'place_slug' => 'nullable|string']);
        if (! empty($data['place_slug'])) {
            $submission->place_id = Entity::where('slug', $data['place_slug'])->value('id');
            $submission->save();
        }
        $service->transition($submission, SubmissionStatus::from($data['to']), $r->user(), $data['notes'] ?? null);

        return back()->with('status', 'ثبت شد.');
    }

    // ------------------------------------------------------------ entities
    public function entities(Request $r)
    {
        $q = Entity::with('type')->whereNull('merged_into_id');
        if ($t = $r->query('type')) {
            $q->ofType($t);
        }
        if ($term = $r->query('q')) {
            $q->where('normalized_name', 'like', '%'.TextNormalizer::normalize($term).'%');
        }
        if ($v = $r->query('visibility')) {
            $q->where('visibility', $v);
        }

        return view('museum.admin.entities', ['entities' => $q->orderBy('canonical_name')->paginate(40)->withQueryString(), 'types' => EntityType::orderBy('domain')->get()]);
    }

    public function entityEdit(Entity $entity)
    {
        $entity->load(['type', 'aliases', 'historicalNames.source']);
        $facts = Fact::where('entity_id', $entity->id)->with(['property', 'sources.source', 'valueEntity', 'conflict'])->orderBy('property_id')->get();
        $properties = Property::orderBy('group')->orderBy('sort')->get()->filter(fn ($p) => $p->appliesTo($entity->type->key));

        return view('museum.admin.entity-edit', ['e' => $entity, 'facts' => $facts, 'properties' => $properties,
            'sources' => Source::orderBy('title')->get(['id', 'uuid', 'title']), 'problems' => app(VerificationService::class)->publishable($entity)]);
    }

    public function entityCreate(Request $r, EntityService $entities)
    {
        $this->can('entities.edit');
        $data = $r->validate(['type' => 'required|exists:museum_entity_types,key', 'canonical_name' => 'required|string|max:500', 'name_en' => 'nullable|string|max:500']);
        $e = $entities->create($data['type'], ['canonical_name' => $data['canonical_name'], 'name_fa' => $data['canonical_name'], 'name_en' => $data['name_en'] ?? null], [], $r->user()->id);

        return redirect()->route('museum.admin.entities.edit', $e);
    }

    public function entityUpdate(Request $r, Entity $entity, EntityService $entities)
    {
        $this->can('entities.edit');
        $data = $r->validate([
            'canonical_name' => 'required|string|max:500', 'name_fa' => 'nullable|string|max:500', 'name_en' => 'nullable|string|max:500',
            'name_ar' => 'nullable|string|max:500', 'name_local' => 'nullable|string|max:500', 'name_local_latin' => 'nullable|string|max:500',
            'summary_fa' => 'nullable|string|max:5000', 'summary_en' => 'nullable|string|max:5000',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'new_alias' => 'nullable|string|max:500', 'parent_slug' => 'nullable|string',
        ]);
        $alias = $data['new_alias'] ?? null;
        $parent = $data['parent_slug'] ?? null;
        unset($data['new_alias'], $data['parent_slug']);
        $entities->update($entity, $data);
        if ($alias) {
            $entities->addAlias($entity, $alias, ['type' => 'spelling_variant']);
        }
        if ($parent && $entity->place) {
            $entities->setPlaceParent($entity, Entity::where('slug', $parent)->firstOrFail());
        }
        ReindexEntityJob::dispatch($entity->id);

        return back()->with('status', 'ذخیره شد.');
    }

    public function entityPublish(Request $r, Entity $entity, VerificationService $v)
    {
        $this->can('entities.publish');
        $data = $r->validate(['action' => 'required|in:publish,unpublish', 'reason' => 'nullable|string|max:1000', 'force' => 'boolean']);
        if ($data['action'] === 'unpublish') {
            $v->unpublish($entity, $r->user()->id, $data['reason'] ?? 'unpublished by editor');

            return back()->with('status', 'از انتشار خارج شد.');
        }
        $ok = $v->publish($entity, $r->user()->id, (bool) ($data['force'] ?? false), $data['reason'] ?? null);

        return back()->with('status', $ok ? 'منتشر شد.' : 'انتشار ممکن نیست: '.implode('؛ ', $v->publishable($entity)));
    }

    public function factStore(Request $r, Entity $entity, FactService $facts, SourceService $sources)
    {
        $this->can('facts.edit');
        $data = $r->validate([
            'property' => 'required|exists:museum_properties,key', 'value' => 'nullable|string|max:20000', 'unknown' => 'boolean',
            'value_entity_slug' => 'nullable|string', 'source_id' => 'required|exists:museum_sources,id', 'page' => 'nullable|string|max:32',
            'quote' => 'required_without:unknown|nullable|string|max:5000', 'status' => 'required|in:unverified,source_verified,expert_verified',
            'census_year' => 'nullable|integer|between:1800,2100', 'scope_place_slug' => 'nullable|string',
        ]);
        $status = VerificationStatus::from($data['status']);
        abort_unless(app(VerificationService::class)->canSetStatus($r->user(), $status), 403);
        $source = Source::findOrFail($data['source_id']);
        $extract = ! empty($data['quote']) ? $sources->extract($source, $data['quote'], ['page' => $data['page'] ?? null]) : null;
        $value = ! empty($data['unknown']) ? FactService::UNKNOWN : (! empty($data['value_entity_slug']) ? Entity::where('slug', $data['value_entity_slug'])->firstOrFail() : $data['value']);
        $facts->assert($entity, $data['property'], $value, [
            'source' => $source, 'extract' => $extract, 'page' => $data['page'] ?? null, 'quote' => $data['quote'] ?? null,
            'status' => $status->value, 'method' => 'manual', 'user_id' => $r->user()->id,
            'qualifiers' => ! empty($data['census_year']) ? ['census_year' => (int) $data['census_year']] : null,
            'scope_place_id' => ! empty($data['scope_place_slug']) ? Entity::where('slug', $data['scope_place_slug'])->value('id') : null,
        ]);
        ReindexEntityJob::dispatch($entity->id);

        return back()->with('status', 'ادعا ثبت شد.');
    }

    public function factUpdate(Request $r, Fact $fact, FactService $facts)
    {
        $this->can('facts.edit');
        $data = $r->validate(['value' => 'required|string|max:20000', 'reason' => 'required|string|max:2000', 'source_id' => 'nullable|exists:museum_sources,id']);
        $facts->updateValue($fact, $data['value'], $r->user()->id, $data['reason'], ! empty($data['source_id']) ? Source::find($data['source_id']) : null);
        ReindexEntityJob::dispatch($fact->entity_id);

        return back()->with('status', 'مقدار اصلاح شد؛ نسخه قبلی حفظ شد.');
    }

    public function historicalNameStore(Request $r, Entity $entity, EntityService $entities, SourceService $sources)
    {
        $this->can('facts.edit');
        $data = $r->validate(['name' => 'required|string|max:500', 'local_pronunciation' => 'nullable|string|max:500', 'period_label' => 'nullable|string|max:255',
            'year_from' => 'nullable|integer', 'year_to' => 'nullable|integer', 'meaning' => 'nullable|string|max:2000', 'naming_reason' => 'nullable|string|max:2000',
            'source_id' => 'required|exists:museum_sources,id', 'page_number' => 'nullable|string|max:32', 'quote' => 'nullable|string|max:5000',
            'verification_status' => 'required|in:unverified,source_verified,expert_verified']);
        $source = Source::find($data['source_id']);
        $extract = ! empty($data['quote']) ? $sources->extract($source, $data['quote'], ['page' => $data['page_number'] ?? null]) : null;
        unset($data['quote']);
        $entities->addHistoricalName($entity, $data + ['source_extract_id' => $extract?->id]);
        ReindexEntityJob::dispatch($entity->id);

        return back()->with('status', 'نام تاریخی ثبت شد.');
    }

    // ------------------------------------------------------------ media
    public function media(Request $r)
    {
        return view('museum.admin.media', ['media' => Media::latest('id')->paginate(30), 'speakers' => Speaker::orderBy('id')->get()]);
    }

    public function mediaStore(Request $r, MediaService $media)
    {
        $this->can('media.manage');
        $data = $r->validate([
            'file' => 'required|file|max:'.(config('museum.media.max_upload_mb') * 1024),
            'title' => 'nullable|string|max:500', 'description' => 'nullable|string|max:5000', 'creator' => 'nullable|string|max:255',
            'year' => 'nullable|integer|between:1000,2100', 'year_precision' => 'nullable|in:exact,circa,decade,unknown',
            'license' => 'required|in:'.implode(',', array_column(License::cases(), 'value')),
            'copyright_holder' => 'nullable|string|max:255', 'rights_statement' => 'nullable|string|max:2000',
            'source_id' => 'nullable|exists:museum_sources,id', 'entity_slug' => 'nullable|string', 'role' => 'nullable|in:primary,gallery,then,now,tutorial',
            'speaker_id' => 'nullable|exists:museum_speakers,id', 'is_synthetic' => 'boolean',
        ]);
        if ($data['license'] === 'restricted') {
            $this->can('media.publish_restricted');
        }
        $m = $media->store($r->file('file'), collect($data)->only(['title', 'description', 'creator', 'year', 'year_precision', 'license', 'copyright_holder', 'rights_statement', 'source_id'])->all()
            + ['uploaded_by' => $r->user()->id]);
        if ($m->media_type === 'audio') {
            $m->audio()->update(['speaker_id' => $data['speaker_id'] ?? null, 'is_synthetic' => (bool) ($data['is_synthetic'] ?? false)]);
        }
        if (! empty($data['entity_slug'])) {
            $e = Entity::where('slug', $data['entity_slug'])->firstOrFail();
            $e->media()->syncWithoutDetaching([$m->id => ['role' => $data['role'] ?? 'gallery']]);
        }
        ProcessMediaJob::dispatch($m->id);

        return back()->with('status', 'رسانه بارگذاری شد (پیش‌نویس؛ برای انتشار پروانه و رضایت را بررسی کنید).');
    }

    public function mediaPublish(Request $r, Media $media, VerificationService $v)
    {
        $this->can('media.manage');
        $data = $r->validate(['visibility' => 'required|in:draft,published,hidden']);
        if ($data['visibility'] === 'published' && ! $media->licenseEnum()->allowsPublicDisplay()) {
            return back()->with('status', 'فایل با پروانه «'.$media->licenseEnum()->label().'» قابل انتشار عمومی نیست.');
        }
        $from = $media->visibility;
        $media->update($data);
        $v->log($media, 'media_visibility', $from, $data['visibility'], $r->user()->id);
        CacheVersion::bump();

        return back()->with('status', 'ذخیره شد.');
    }

    // ------------------------------------------------------------ research agent
    public function research()
    {
        return view('museum.admin.research', ['tasks' => ResearchTask::latest('id')->paginate(20)]);
    }

    public function researchStore(Request $r)
    {
        $this->can('research.run');
        $data = $r->validate(['topic' => 'required|string|max:500']);
        $task = ResearchTask::create(['topic' => $data['topic'], 'status' => 'queued', 'requested_by' => $r->user()->id]);
        RunResearchTaskJob::dispatch($task->id);

        return back()->with('status', 'کار پژوهشی در صف قرار گرفت.');
    }
}
