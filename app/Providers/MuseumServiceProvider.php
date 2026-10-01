<?php

namespace App\Providers;

use App\Museum\Ai\AnthropicLlmClient;
use App\Museum\Ai\LlmClient;
use App\Museum\Ai\NullLlmClient;
use App\Museum\Search\Embeddings\EmbeddingProvider;
use App\Museum\Search\SearchEngine;
use App\Museum\Search\Vector\VectorStore;
use App\Museum\Services\FactService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class MuseumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FactService::class);
        $this->app->singleton(LlmClient::class, function () {
            $cfg = config('museum.llm');

            return $cfg['driver'] === 'anthropic' ? new AnthropicLlmClient($cfg) : new NullLlmClient;
        });
        $this->app->singleton(SearchEngine::class, fn ($app) => SearchEngine::make(config('museum.search.driver')));
        $this->app->singleton(EmbeddingProvider::class, fn () => EmbeddingProvider::make(config('museum.embeddings')));
        $this->app->singleton(VectorStore::class, fn () => VectorStore::make(config('museum.vector')));
    }

    public function boot(): void
    {
        // Stable morph aliases so stored polymorphic references survive class renames.
        Relation::enforceMorphMap([
            'user' => \App\Models\User::class,
            'entity' => \App\Models\Museum\Entity::class,
            'fact' => \App\Models\Museum\Fact::class,
            'fact_conflict' => \App\Models\Museum\FactConflict::class,
            'source' => \App\Models\Museum\Source::class,
            'source_extract' => \App\Models\Museum\SourceExtract::class,
            'raw_document' => \App\Models\Museum\RawDocument::class,
            'document_page' => \App\Models\Museum\DocumentPage::class,
            'interview_segment' => \App\Models\Museum\InterviewSegment::class,
            'media' => \App\Models\Museum\Media::class,
            'sentence' => \App\Models\Museum\Sentence::class,
            'word' => \App\Models\Museum\Word::class,
            'proverb' => \App\Models\Museum\Proverb::class,
            'recipe' => \App\Models\Museum\Recipe::class,
            'grammar_rule' => \App\Models\Museum\GrammarRule::class,
            'historical_name' => \App\Models\Museum\HistoricalName::class,
            'text_chunk' => \App\Models\Museum\TextChunk::class,
            'extraction_candidate' => \App\Models\Museum\ExtractionCandidate::class,
            'community_submission' => \App\Models\Museum\CommunitySubmission::class,
            'interview' => \App\Models\Museum\Interview::class,
            'research_task' => \App\Models\Museum\ResearchTask::class,
        ]);

        Gate::define('museum', fn ($user, string $permission) => $user->hasMuseumPermission($permission));
        Gate::define('museum-admin-panel', fn ($user) => $user->museumRoles()->exists());
    }
}
