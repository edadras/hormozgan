<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\CommunitySubmission;
use App\Museum\Services\EntityService;
use App\Museum\Services\FactService;
use App\Museum\Services\VerificationService;
use Illuminate\Support\Facades\Queue;

class ApiTest extends MuseumTestCase
{
    private function published(string $type, string $name, array $attrs = [], array $ext = [])
    {
        $e = $this->entity($type, $name, $attrs, $ext);
        app(FactService::class)->assert($e, 'description', 'Fixture description of '.$name, ['source' => $this->source(), 'status' => 'source_verified', 'page' => '3']);
        app(VerificationService::class)->publish($e->fresh(), null);

        return $e->fresh();
    }

    public function test_places_list_and_detail_with_provenance(): void
    {
        $county = $this->published('county', 'Fixture County', ['latitude' => 27.0, 'longitude' => 56.0]);
        $village = $this->published('village', 'Fixture Village', ['latitude' => 27.1, 'longitude' => 56.1], ['parent_id' => $county->id]);
        $this->entity('village', 'Draft Village', [], ['parent_id' => $county->id]); // unpublished

        $this->getJson('/api/museum/places?type=village')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', $village->slug);
        $this->getJson('/api/museum/places?parent='.$county->slug)->assertOk()->assertJsonPath('meta.total', 1);

        $res = $this->getJson('/api/museum/places/'.$village->slug)->assertOk();
        $res->assertJsonPath('data.breadcrumbs.0.slug', $county->slug);
        $fact = $res->json('data.facts.general.0');
        $this->assertSame('3', $fact['sources'][0]['page']);

        $this->getJson('/api/museum/knowledge/facts/'.$fact['uuid'])->assertOk()
            ->assertJsonPath('data.evidence.0.page', '3')
            ->assertJsonPath('data.revisions.0.change', 'created');

        $this->getJson('/api/museum/places/draft-village')->assertNotFound();
    }

    public function test_pagination_is_bounded(): void
    {
        $this->getJson('/api/museum/entities?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_map_geojson(): void
    {
        $this->published('city', 'Fixture City', ['latitude' => 27.18, 'longitude' => 56.27]);
        $this->getJson('/api/museum/map')->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonPath('features.0.geometry.coordinates', [56.27, 27.18]);
    }

    public function test_unknown_values_are_shown_as_unknown(): void
    {
        $e = $this->published('village', 'Fixture Village');
        app(FactService::class)->assertUnknown($e, 'founding_year', ['source' => $this->source(), 'status' => 'source_verified']);
        \App\Museum\Support\CacheVersion::bump();
        $facts = collect($this->getJson('/api/museum/entities/'.$e->slug)->json('data.facts'))->flatten(1);
        $this->assertTrue($facts->firstWhere('property', 'founding_year')['is_unknown']);
    }

    public function test_submission_requires_consent_and_is_not_published(): void
    {
        Queue::fake();
        $this->postJson('/api/museum/submissions', ['submission_type' => 'word', 'payload' => ['word' => 'x']])
            ->assertStatus(422)->assertJsonValidationErrors('consent_publish');
        $this->postJson('/api/museum/submissions', [
            'submission_type' => 'word', 'payload' => ['word' => 'fixtureword', 'meaning_fa' => 'معنی'], 'consent_publish' => true,
        ])->assertCreated()->assertJsonPath('status', 'submitted');
        $this->assertSame('submitted', CommunitySubmission::sole()->status);
    }

    public function test_ask_endpoint_without_llm_lists_sources_only(): void
    {
        config(['museum.llm.driver' => 'null']);
        $this->app->forgetInstance(\App\Museum\Ai\LlmClient::class);
        $e = $this->published('city', 'Fixture Harbour');
        app(\App\Museum\Search\SearchIndexer::class)->indexEntity($e);
        $this->postJson('/api/museum/ask', ['question' => 'Fixture Harbour'])->assertOk()
            ->assertJsonPath('status', 'retrieval_only')->assertJsonPath('answer', null);
    }

    public function test_search_endpoint(): void
    {
        $e = $this->published('city', 'بندرعباس', ['name_en' => 'Bandar Abbas']);
        app(\App\Museum\Search\SearchIndexer::class)->indexEntity($e);
        $this->getJson('/api/museum/search?q='.urlencode('بندر عباس'))->assertOk()->assertJsonPath('data.0.type', 'city');
    }
}
