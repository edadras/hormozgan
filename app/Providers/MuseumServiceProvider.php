<?php

namespace App\Providers;

use App\Models\Museum\CommunitySubmission;
use App\Models\Museum\DocumentPage;
use App\Models\Museum\Entity;
use App\Models\Museum\ExtractionCandidate;
use App\Models\Museum\Fact;
use App\Models\Museum\FactConflict;
use App\Models\Museum\GrammarRule;
use App\Models\Museum\HistoricalName;
use App\Models\Museum\Interview;
use App\Models\Museum\InterviewSegment;
use App\Models\Museum\Media;
use App\Models\Museum\Proverb;
use App\Models\Museum\RawDocument;
use App\Models\Museum\Recipe;
use App\Models\Museum\ResearchTask;
use App\Models\Museum\Sentence;
use App\Models\Museum\Source;
use App\Models\Museum\SourceExtract;
use App\Models\Museum\TextChunk;
use App\Models\Museum\Word;
use App\Models\User;
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
            'user' => User::class,
            'entity' => Entity::class,
            'fact' => Fact::class,
            'fact_conflict' => FactConflict::class,
            'source' => Source::class,
            'source_extract' => SourceExtract::class,
            'raw_document' => RawDocument::class,
            'document_page' => DocumentPage::class,
            'interview_segment' => InterviewSegment::class,
            'media' => Media::class,
            'sentence' => Sentence::class,
            'word' => Word::class,
            'proverb' => Proverb::class,
            'recipe' => Recipe::class,
            'grammar_rule' => GrammarRule::class,
            'historical_name' => HistoricalName::class,
            'text_chunk' => TextChunk::class,
            'extraction_candidate' => ExtractionCandidate::class,
            'community_submission' => CommunitySubmission::class,
            'interview' => Interview::class,
            'research_task' => ResearchTask::class,
        ]);

        Gate::define('museum', fn ($user, string $permission) => $user->hasMuseumPermission($permission));
        Gate::define('museum-admin-panel', fn ($user) => $user->museumRoles()->exists());
    }
}
