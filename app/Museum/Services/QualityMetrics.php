<?php

namespace App\Museum\Services;

use App\Models\Museum\AiJob;
use App\Models\Museum\AudioRecording;
use App\Models\Museum\DuplicateCandidate;
use App\Models\Museum\Entity;
use App\Models\Museum\EntityRelationship;
use App\Models\Museum\EntityType;
use App\Models\Museum\ExtractionCandidate;
use App\Models\Museum\Fact;
use App\Models\Museum\FactConflict;
use App\Models\Museum\Media;
use App\Models\Museum\QualitySnapshot;
use App\Models\Museum\RawDocument;
use App\Models\Museum\Sentence;
use App\Models\Museum\Source;
use App\Models\Museum\TextChunk;
use App\Museum\Enums\VerificationStatus;
use Illuminate\Support\Facades\DB;

/** Data Quality (section 43) and Big Data (section 44) dashboard metrics. */
class QualityMetrics
{
    public function quality(): array
    {
        $verified = VerificationStatus::atLeastValues(VerificationStatus::SourceVerified);
        $low = (float) config('museum.extraction.low_confidence_threshold', 0.6);

        return [
            'totals' => [
                'entities' => Entity::whereNull('merged_into_id')->count(),
                'published_entities' => Entity::published()->count(),
                'facts' => Fact::count(),
                'verified_facts' => Fact::whereIn('verification_status', $verified)->count(),
                'unverified_facts' => Fact::whereNotIn('verification_status', $verified)->count(),
                'sources' => Source::count(),
                'words' => Entity::ofType('word')->count(),
                'sentences' => Sentence::count(),
                'audio_recordings' => AudioRecording::count(),
                'places' => Entity::ofType('place')->count(),
                'interviews' => Entity::ofType('interview')->count(),
                'photos' => Media::where('media_type', 'image')->count(),
                'documents' => Entity::ofType('document')->count(),
            ],
            'issues' => [
                'missing_source' => Fact::where('sources_count', 0)->count(),
                'possible_duplicates' => DuplicateCandidate::where('status', 'pending')->count(),
                'conflicting_facts' => FactConflict::where('status', 'open')->count(),
                'low_confidence' => Fact::whereNotNull('confidence_score')->where('confidence_score', '<', $low)->count(),
                'needs_review' => Fact::whereIn('verification_status', ['unverified', 'ai_extracted'])->count()
                    + ExtractionCandidate::where('status', 'in_review')->count(),
                'media_unknown_license' => Media::where('license', 'unknown')->count(),
                'entities_without_facts' => Entity::whereNull('merged_into_id')->where('facts_count', 0)->count(),
            ],
            'facts_by_status' => Fact::select('verification_status', DB::raw('count(*) as n'))->groupBy('verification_status')->pluck('n', 'verification_status'),
            'entities_by_type' => Entity::whereNull('merged_into_id')->select('entity_type_id', DB::raw('count(*) as n'))->groupBy('entity_type_id')
                ->pluck('n', 'entity_type_id')->mapWithKeys(fn ($n, $id) => [array_search($id, EntityType::map(), true) ?: $id => $n]),
            'sources_by_tier' => Source::select('reliability_tier', DB::raw('count(*) as n'))->groupBy('reliability_tier')->pluck('n', 'reliability_tier'),
        ];
    }

    public function bigData(): array
    {
        $queue = [];
        if (config('queue.default') === 'database') {
            $queue = DB::table('jobs')->select('queue', DB::raw('count(*) as n'))->groupBy('queue')->pluck('n', 'queue')->all();
        } elseif (config('queue.default') === 'redis') {
            foreach (config('museum.queues') as $name) {
                try {
                    $queue[$name] = \Illuminate\Support\Facades\Queue::size($name);
                } catch (\Throwable) {
                    $queue[$name] = null;
                }
            }
        }

        return [
            'documents_crawled' => RawDocument::count(),
            'pages_processed' => (int) RawDocument::sum('page_count') + DB::table('museum_document_pages')->whereNotNull('ocr_text_raw')->count(),
            'text_chunks' => TextChunk::count(),
            'embeddings' => DB::table('museum_embeddings')->count(),
            'entities_extracted' => ExtractionCandidate::where('kind', 'entity')->count(),
            'candidates_by_status' => ExtractionCandidate::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'relationships' => EntityRelationship::count(),
            'words' => Entity::ofType('word')->count(),
            'locations' => Entity::whereNotNull('latitude')->count(),
            'media' => Media::count(),
            'processing_queue' => $queue,
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'ai_jobs' => AiJob::select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'ai_tokens' => ['input' => (int) AiJob::sum('input_tokens'), 'output' => (int) AiJob::sum('output_tokens')],
            'storage_bytes' => [
                'raw' => (int) RawDocument::sum('size_bytes'),
                'media' => (int) Media::sum('size_bytes'),
            ],
        ];
    }

    public function publicCounts(): array
    {
        return [
            'entities' => Entity::published()->count(),
            'places' => Entity::published()->ofType('place')->count(),
            'words' => Entity::published()->ofType('word')->count(),
            'public_facts' => Fact::public()->count(),
            'sources' => Source::count(),
        ];
    }

    public function snapshot(): QualitySnapshot
    {
        return QualitySnapshot::create(['metrics' => ['quality' => $this->quality(), 'big_data' => $this->bigData()], 'created_at' => now()]);
    }
}
