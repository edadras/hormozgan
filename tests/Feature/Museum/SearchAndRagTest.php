<?php

namespace Tests\Feature\Museum;

use App\Museum\Ai\LlmClient;
use App\Museum\Search\RagService;
use App\Museum\Search\SearchEngine;
use App\Museum\Search\SearchIndexer;
use App\Museum\Services\EntityService;
use App\Museum\Services\FactService;
use App\Museum\Services\VerificationService;
use Tests\Support\FakeLlmClient;

class SearchAndRagTest extends MuseumTestCase
{
    private function publishedPlace(string $fa, string $en, ?string $oldName = null)
    {
        $e = $this->entity('city', $fa, ['name_fa' => $fa, 'name_en' => $en]);
        $src = $this->source(['title' => 'Fixture gazetteer for '.$en]);
        app(FactService::class)->assert($e, 'founding_year', 1890, ['source' => $src, 'status' => 'source_verified', 'page' => '7']);
        if ($oldName) {
            app(EntityService::class)->addHistoricalName($e, ['name' => $oldName, 'source_id' => $src->id]);
        }
        app(VerificationService::class)->publish($e->fresh(), null);
        app(SearchIndexer::class)->indexEntity($e->fresh());

        return $e;
    }

    public function test_search_matches_spellings_and_historical_names(): void
    {
        $city = $this->publishedPlace('بندرعباس', 'Bandar Abbas', 'Fixture Oldport');
        $engine = app(SearchEngine::class);
        foreach (['بندر عباس', 'Bandar-e Abbas', 'fixture oldport'] as $q) {
            $hits = $engine->search($q)['hits'];
            $this->assertNotEmpty($hits, $q);
            $this->assertSame($city->id, $hits[0]['doc']->searchable_id, $q);
        }
    }

    public function test_unpublished_entities_are_not_found_publicly(): void
    {
        $e = $this->entity('city', 'Fixture Hidden');
        app(SearchIndexer::class)->indexEntity($e);
        $this->assertSame(0, app(SearchEngine::class)->search('Fixture Hidden')['total']);
        $this->assertSame(1, app(SearchEngine::class)->search('Fixture Hidden', ['public_only' => false])['total']);
    }

    public function test_rag_answers_only_with_valid_citations(): void
    {
        $this->publishedPlace('Fixture Town', 'Fixture Town');
        $fake = new FakeLlmClient([['answer' => 'It was founded in 1890 [1].', 'used_citations' => [1], 'insufficient_information' => false]]);
        $this->app->instance(LlmClient::class, $fake);

        $q = app(RagService::class)->ask('When was Fixture Town founded?');
        $this->assertSame('answered', $q->status);
        $this->assertSame(1, $q->citations[0]['n']);
        $this->assertStringContainsString('/museum/facts/', $q->citations[0]['url']);
        $this->assertStringContainsString('<context>', $fake->calls[0]['user']);
    }

    public function test_rag_withholds_uncited_or_out_of_range_answers(): void
    {
        $this->publishedPlace('Fixture Town', 'Fixture Town');
        $this->app->instance(LlmClient::class, new FakeLlmClient([
            ['answer' => 'It was founded in 1890.', 'used_citations' => [], 'insufficient_information' => false],
        ]));
        $q = app(RagService::class)->ask('When was Fixture Town founded?');
        $this->assertSame('rejected_uncited', $q->status);
        $this->assertNull($q->answer);

        $this->app->instance(LlmClient::class, new FakeLlmClient([
            ['answer' => 'Founded in 1890 [1] and had a famous port [9].', 'used_citations' => [1, 9], 'insufficient_information' => false],
        ]));
        $this->assertSame('rejected_uncited', app(RagService::class)->ask('When was Fixture Town founded?')->status);
    }

    public function test_rag_does_not_call_llm_without_context(): void
    {
        $fake = new FakeLlmClient([]);
        $this->app->instance(LlmClient::class, $fake);
        $q = app(RagService::class)->ask('Something entirely unknown xyzzy');
        $this->assertSame('insufficient_context', $q->status);
        $this->assertCount(0, $fake->calls);
    }
}
