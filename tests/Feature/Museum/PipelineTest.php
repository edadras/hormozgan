<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\CrawlerSource;
use App\Models\Museum\ExtractionCandidate;
use App\Models\Museum\Fact;
use App\Models\Museum\RawDocument;
use App\Models\Museum\Source;
use App\Models\Museum\TextChunk;
use App\Museum\Ai\LlmClient;
use App\Museum\Ai\LlmExtractor;
use App\Museum\Importers\SourceRegistryImporter;
use App\Museum\Pipeline\Chunker;
use App\Museum\Pipeline\DocumentFetcher;
use App\Museum\Pipeline\RobotsPolicy;
use App\Museum\Pipeline\TextExtractor;
use App\Museum\Services\CandidateProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeLlmClient;

class PipelineTest extends MuseumTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('museum_raw');
        config(['museum.crawler.min_delay_ms' => 0]);
    }

    public function test_robots_rules(): void
    {
        $robots = "User-agent: *\nDisallow: /w/\nAllow: /w/api.php?action=mobileview&\n\nUser-agent: OtherBot\nDisallow: /";
        $r = RobotsPolicy::parse($robots, 'HormozganDigitalMuseumBot');
        $this->assertFalse(RobotsPolicy::evaluate($r['rules'], '/w/api.php?action=wbgetentities'));
        $this->assertTrue(RobotsPolicy::evaluate($r['rules'], '/w/api.php?action=mobileview&x=1'));
        $this->assertTrue(RobotsPolicy::evaluate($r['rules'], '/wiki/Special:EntityData/Q1.json'));

        $specific = RobotsPolicy::parse("User-agent: *\nAllow: /\n\nUser-agent: HormozganDigitalMuseumBot\nDisallow: /private\nCrawl-delay: 7", 'HormozganDigitalMuseumBot');
        $this->assertFalse(RobotsPolicy::evaluate($specific['rules'], '/private/a.pdf'));
        $this->assertSame(7.0, $specific['delay']);
    }

    public function test_fetch_is_gated_by_terms_policy_and_robots(): void
    {
        Http::fake([
            'example.org/robots.txt' => Http::response("User-agent: *\nDisallow: /secret/"),
            'example.org/docs/a.html' => Http::response('<html><body><main><p>متن آزمایشی درباره میناب</p></main></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $source = $this->source(['url' => 'https://example.org/docs/', 'crawl_policy' => 'allowed']);
        $cs = CrawlerSource::create(['source_id' => $source->id, 'name' => 'fixture', 'kind' => 'http_document',
            'allowed_patterns' => ['https://example.org/*'], 'enabled' => true, 'terms_reviewed' => false, 'crawl_delay_ms' => 0]);
        $fetcher = app(DocumentFetcher::class);

        $this->assertSame('terms of use not reviewed', $fetcher->fetch($cs, 'https://example.org/docs/a.html')['reason']);
        $cs->update(['terms_reviewed' => true]);
        $this->assertStringContainsString('robots', $fetcher->fetch($cs, 'https://example.org/secret/x.pdf')['reason']);
        $this->assertSame('URL outside allowed patterns', $fetcher->fetch($cs, 'https://other.org/a')['reason']);

        $res = $fetcher->fetch($cs, 'https://example.org/docs/a.html');
        $this->assertSame('fetched', $res['status']);
        Storage::disk('museum_raw')->assertExists($res['raw']->path);

        // Identical bytes are stored once.
        $again = $fetcher->fetch($cs, 'https://example.org/docs/a.html');
        $this->assertSame($res['raw']->id, $again['raw']->id);

        $raw = app(TextExtractor::class)->extract($res['raw']);
        $this->assertSame('text_extracted', $raw->status);
        $this->assertStringContainsString('میناب', $raw->text());
    }

    public function test_chunker_is_page_aware(): void
    {
        config(['museum.extraction.chunk_chars' => 200, 'museum.extraction.chunk_overlap' => 20]);
        $page1 = str_repeat('جمله اول درباره قشم. ', 20);
        $page2 = 'صفحه دوم درباره هرمز و جزیره هرمز.';
        $parts = app(Chunker::class)->split($page1."\f".$page2);
        $this->assertGreaterThan(1, count($parts));
        $this->assertSame(2, end($parts)['page']);
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(260, mb_strlen($p['text']));
        }
    }

    public function test_llm_extraction_rejects_unsupported_claims_and_routes_supported_ones(): void
    {
        $minab = $this->entity('city', 'میناب', ['name_fa' => 'میناب', 'name_en' => 'Minab']);
        $source = $this->source();
        $raw = RawDocument::create(['disk' => 'museum_raw', 'path' => 'x.txt', 'sha256' => str_repeat('b', 64), 'source_id' => $source->id]);
        $chunk = TextChunk::create(['chunkable_type' => 'raw_document', 'chunkable_id' => $raw->id, 'source_id' => $source->id,
            'page_number' => '12', 'text' => 'Minab was founded in 1890 according to the local elders.', 'normalized_text' => 'x', 'text_hash' => str_repeat('c', 64)]);

        $fake = new FakeLlmClient([[
            'entities' => [
                ['name' => 'Minab', 'type' => 'city', 'name_language' => 'en', 'evidence_quote' => 'Minab was founded', 'confidence' => 0.9],
                ['name' => 'Atlantis', 'type' => 'city', 'name_language' => 'en', 'evidence_quote' => 'Atlantis sank', 'confidence' => 0.9],
            ],
            'facts' => [
                ['subject' => 'Minab', 'subject_type' => 'city', 'property' => 'founding_year', 'value' => '1890', 'qualifiers' => '',
                    'evidence_quote' => 'founded in 1890', 'confidence' => 0.8],
                ['subject' => 'Minab', 'subject_type' => 'city', 'property' => 'made_up_property', 'value' => 'x', 'qualifiers' => '',
                    'evidence_quote' => 'Minab was founded', 'confidence' => 0.8],
            ],
            'relationships' => [],
            'historical_names' => [],
        ]]);
        $this->app->instance(LlmClient::class, $fake);

        $job = app(LlmExtractor::class)->extractChunk($chunk);
        $this->assertSame('succeeded', $job->status);
        $this->assertSame(2, $job->candidates_count);
        $this->assertSame(2, $job->rejected_count);
        $this->assertSame('evidence quote not found in source text',
            ExtractionCandidate::where('surface_form', 'Atlantis')->value('rejection_reason'));

        app(CandidateProcessor::class)->processPending();
        $fact = Fact::where('entity_id', $minab->id)->sole();
        $this->assertSame('ai_extracted', $fact->verification_status);
        $this->assertSame(1890, $fact->value_year_from);
        $fs = $fact->sources()->with('extract')->sole();
        $this->assertSame('12', $fs->page_number);
        $this->assertSame('founded in 1890', $fs->extract->original_text);
        // AI facts never become public by themselves.
        $this->assertSame(0, Fact::public()->count());
    }

    public function test_discovery_requires_independent_sources(): void
    {
        config(['museum.extraction.discovery_min_sources' => 2]);
        $s1 = $this->source();
        $s2 = $this->source();
        $mk = fn (Source $s) => ExtractionCandidate::create(['kind' => 'entity', 'entity_type_key' => 'food', 'surface_form' => 'Fixture Dish',
            'normalized_form' => 'fixture dish', 'payload' => [], 'source_id' => $s->id, 'status' => 'pending']);
        $a = $mk($s1);
        $processor = app(CandidateProcessor::class);
        $this->assertSame('needs_evidence', $processor->process($a));
        $b = $mk($s2);
        $this->assertSame('in_review', $processor->process($b));
        $this->assertSame(2, $a->fresh()->evidence_count);
        $this->assertSame('in_review', $a->fresh()->status);
    }

    public function test_source_registry_import_report(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reg');
        file_put_contents($path, json_encode(['sources' => [
            ['title' => 'Fixture A', 'source_type' => 'article', 'url' => 'https://example.org/a?utm_source=x'],
            ['title' => 'Fixture A dup', 'source_type' => 'article', 'url' => 'https://example.org/a'],
            ['title' => '', 'source_type' => 'article'],
            ['title' => 'Bad type', 'source_type' => 'blog'],
        ]]));
        $batch = app(SourceRegistryImporter::class)->run($path)->batch->fresh();
        $this->assertSame(4, $batch->sources_found);
        $this->assertSame(1, $batch->sources_accepted);
        $this->assertSame(1, $batch->entities_matched); // the duplicate URL (tracking params ignored)
        $this->assertSame(2, $batch->sources_rejected);
        $src = Source::sole();
        $this->assertSame('unknown', $src->license);
        $this->assertSame('pending_review', $src->crawl_policy);
        $this->assertFalse(CrawlerSource::sole()->enabled);
    }

    public function test_bundled_registry_file_is_valid(): void
    {
        $batch = app(SourceRegistryImporter::class)->run(base_path('database/data/source_registry.json'))->batch->fresh();
        $this->assertSame(0, $batch->sources_rejected);
        $this->assertSame($batch->sources_found, $batch->sources_accepted);
        $this->assertSame(0, Source::where('crawl_policy', '!=', 'pending_review')->count());
    }
}
