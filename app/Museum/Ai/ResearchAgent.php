<?php

namespace App\Museum\Ai;

use App\Models\Museum\CrawlerSource;
use App\Models\Museum\FactConflict;
use App\Models\Museum\ResearchTask;
use App\Models\Museum\Source;
use App\Models\Museum\TextChunk;
use App\Museum\Jobs\FetchCrawlerSourceJob;
use App\Museum\Services\CandidateProcessor;
use App\Museum\Support\TextNormalizer;

/**
 * Internal research agent (section 58). A deterministic workflow, not a free-roaming agent:
 *
 *  1 discover topic   – normalize the topic, collect search terms (incl. entity aliases)
 *  2 find sources     – source registry (topics/regions/title) + already-ingested chunks
 *  3 acquire          – queue fetches only for crawler sources whose terms were reviewed
 *  4 extract          – LLM extraction on chunks that mention the topic (evidence-checked)
 *  5 compare          – route candidates; count contradictions found by the conflict engine
 *  6 hand over        – everything lands in review queues; nothing is published
 */
class ResearchAgent
{
    public function __construct(private LlmExtractor $extractor, private CandidateProcessor $candidates) {}

    public function run(ResearchTask $task, int $maxChunks = 25): ResearchTask
    {
        $steps = [];
        $log = function (string $step, array $data = []) use (&$steps, $task) {
            $steps[] = ['step' => $step, 'at' => now()->toIso8601String()] + $data;
            $task->forceFill(['steps' => $steps])->save();
        };
        $task->forceFill(['status' => 'running'])->save();

        try {
            $terms = collect([$task->topic])
                ->merge($task->entity?->aliases()->pluck('alias') ?? [])
                ->map(fn ($t) => TextNormalizer::normalize($t))->filter()->unique()->values();
            $log('discover_topic', ['terms' => $terms->all()]);

            $sources = Source::query()->where(function ($q) use ($terms, $task) {
                foreach ($terms as $t) {
                    $q->orWhere('title', 'like', '%'.$t.'%');
                }
                $q->orWhere('topics', 'like', '%'.$task->topic.'%')->orWhere('regions', 'like', '%'.$task->topic.'%');
            })->limit(50)->get();
            $chunkQuery = TextChunk::query()->where(function ($q) use ($terms) {
                foreach ($terms as $t) {
                    $q->orWhere('normalized_text', 'like', '%'.$t.'%');
                }
            });
            $log('find_sources', ['registry_matches' => $sources->pluck('id')->all(), 'matching_chunks' => (clone $chunkQuery)->count()]);

            $queued = [];
            foreach (CrawlerSource::whereIn('source_id', $sources->pluck('id'))->where('enabled', true)->where('terms_reviewed', true)->get() as $cs) {
                FetchCrawlerSourceJob::dispatch($cs->id);
                $queued[] = $cs->id;
            }
            $pending = CrawlerSource::whereIn('source_id', $sources->pluck('id'))->where('terms_reviewed', false)->count();
            $log('acquire', ['queued_crawlers' => $queued, 'awaiting_terms_review' => $pending]);

            $jobs = 0;
            $candidates = 0;
            if ($this->extractorAvailable()) {
                foreach ($chunkQuery->limit($maxChunks)->get() as $chunk) {
                    $job = $this->extractor->extractChunk($chunk);
                    $jobs++;
                    $candidates += $job->candidates_count;
                }
            }
            $log('extract', ['extraction_jobs' => $jobs, 'candidates' => $candidates, 'llm_available' => $this->extractorAvailable()]);

            $before = FactConflict::where('status', 'open')->count();
            $stats = $this->candidates->processPending();
            $contradictions = max(0, FactConflict::where('status', 'open')->count() - $before);
            $log('compare', ['routing' => $stats, 'new_conflicts' => $contradictions]);

            $task->forceFill([
                'status' => 'awaiting_review',
                'source_ids' => $sources->pluck('id')->all(),
                'candidates_count' => $candidates,
                'contradictions_count' => $contradictions,
            ])->save();
            $log('handover', ['note' => 'All findings are in the verification queues; nothing was published.']);
        } catch (\Throwable $e) {
            $task->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
        }

        return $task;
    }

    private function extractorAvailable(): bool
    {
        return app(LlmClient::class)->available();
    }
}
