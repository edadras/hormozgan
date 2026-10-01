<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extraction/discovery, AI job log, duplicate detection, research agent,
 * search index, RAG chunks/embeddings, community submissions, quality snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('museum_ai_jobs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            // extract, ocr_cleanup, transcribe, embed, answer, research, dedup_judge
            $t->string('job_type', 24)->index();
            $t->string('provider', 32)->nullable();
            $t->string('model', 96)->nullable();
            $t->string('status', 16)->default('queued')->index(); // queued, running, succeeded, failed
            $t->nullableMorphs('subject');
            $t->char('input_hash', 64)->nullable()->index();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->unsignedInteger('latency_ms')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->longText('raw_output')->nullable();          // AI output is preserved verbatim
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('museum_extraction_jobs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $t->morphs('target');                             // raw_document, document_page, interview_segment, source_extract
            $t->string('extractor', 32);                      // llm, gazetteer, structured
            $t->string('prompt_version', 16)->nullable();
            $t->foreignId('ai_job_id')->nullable()->constrained('museum_ai_jobs')->nullOnDelete();
            $t->foreignId('import_batch_id')->nullable()->constrained('museum_import_batches')->nullOnDelete();
            $t->string('status', 16)->default('queued')->index();
            $t->unsignedInteger('candidates_count')->default(0);
            $t->unsignedInteger('rejected_count')->default(0);
            $t->json('stats')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
        });

        Schema::create('museum_extraction_candidates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('extraction_job_id')->nullable()->constrained('museum_extraction_jobs')->nullOnDelete();
            $t->string('kind', 16)->index();                  // entity, alias, fact, relationship, historical_name
            $t->string('entity_type_key', 64)->nullable()->index();
            $t->string('surface_form', 500)->nullable();
            $t->string('normalized_form', 500)->nullable()->index();
            $t->json('payload');
            $t->text('evidence_quote')->nullable();
            $t->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $t->foreignId('source_extract_id')->nullable()->constrained('museum_source_extracts')->nullOnDelete();
            $t->foreignId('matched_entity_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->decimal('match_score', 5, 4)->nullable();
            $t->decimal('confidence_score', 5, 4)->nullable();
            $t->unsignedInteger('evidence_count')->default(1); // discovery: number of independent sources seen in
            $t->json('evidence_source_ids')->nullable();
            // pending, needs_evidence, in_review, accepted, rejected, merged
            $t->string('status', 16)->default('pending')->index();
            $t->string('rejection_reason')->nullable();
            $t->nullableMorphs('result');                     // created fact/entity
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('museum_duplicate_candidates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entity_a_id')->constrained('museum_entities')->cascadeOnDelete();
            $t->foreignId('entity_b_id')->constrained('museum_entities')->cascadeOnDelete();
            $t->decimal('score', 5, 4);
            $t->string('method', 32);                         // alias_exact, fuzzy_name, geo_proximity, external_id
            $t->json('evidence')->nullable();
            $t->string('status', 16)->default('pending')->index(); // pending, merged, distinct
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['entity_a_id', 'entity_b_id']);
        });

        Schema::create('museum_research_tasks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('topic', 500);
            $t->foreignId('entity_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->string('status', 16)->default('queued')->index(); // queued, running, awaiting_review, done, failed
            $t->json('steps')->nullable();                    // agent step log
            $t->json('source_ids')->nullable();
            $t->unsignedInteger('candidates_count')->default(0);
            $t->unsignedInteger('contradictions_count')->default(0);
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('error')->nullable();
            $t->timestamps();
        });

        // Denormalized search index (one row per searchable record).
        Schema::create('museum_search_documents', function (Blueprint $t) {
            $t->id();
            $t->morphs('searchable');
            $t->string('doc_type', 32)->index();              // entity type key, sentence, document_page, segment, media
            $t->string('title', 500);
            $t->text('aliases')->nullable();                  // all names, normalized, space separated
            $t->longText('body')->nullable();
            $t->longText('normalized_text');                  // title + aliases + body normalized
            $t->text('compact_text')->nullable();             // normalized title+aliases without spaces
            $t->string('url', 500)->nullable();
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->smallInteger('year_from')->nullable();
            $t->smallInteger('year_to')->nullable();
            $t->string('verification_status', 24)->nullable();
            $t->boolean('is_public')->default(false)->index();
            $t->decimal('boost', 5, 2)->default(1);
            $t->timestamps();
            $t->unique(['searchable_type', 'searchable_id']);
        });

        Schema::create('museum_text_chunks', function (Blueprint $t) {
            $t->id();
            $t->morphs('chunkable');                          // raw_document, document_page, segment, entity, source_extract
            $t->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $t->foreignId('entity_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->unsignedInteger('seq')->default(0);
            $t->string('page_number', 32)->nullable();
            $t->string('locator')->nullable();
            $t->longText('text');
            $t->longText('normalized_text');
            $t->unsignedInteger('token_estimate')->default(0);
            $t->char('text_hash', 64)->index();
            $t->boolean('is_public')->default(false)->index();
            $t->timestamps();
        });

        Schema::create('museum_embeddings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('text_chunk_id')->constrained('museum_text_chunks')->cascadeOnDelete();
            $t->string('model', 96);
            $t->unsignedSmallInteger('dimensions');
            $t->binary('vector');                             // packed float32 little-endian, L2-normalized
            $t->timestamps();
            $t->unique(['text_chunk_id', 'model']);
        });

        Schema::create('museum_rag_queries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->text('question');
            $t->json('analysis')->nullable();
            $t->json('retrieved')->nullable();                // chunk ids + scores
            $t->longText('answer')->nullable();
            $t->json('citations')->nullable();
            $t->string('status', 16);                         // answered, insufficient_context, rejected_uncited, error
            $t->string('model', 96)->nullable();
            $t->unsignedInteger('latency_ms')->nullable();
            $t->tinyInteger('feedback')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->char('ip_hash', 64)->nullable();
            $t->timestamps();
        });

        Schema::create('museum_community_submissions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            // word, pronunciation, proverb, story, photo, video, old_name, food, recipe, historical_info, memory, music
            $t->string('submission_type', 24)->index();
            $t->string('title', 500)->nullable();
            $t->json('payload');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('submitter_name')->nullable();
            $t->text('submitter_contact')->nullable();        // encrypted; never public
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->string('place_text')->nullable();
            $t->string('dialect_text')->nullable();
            $t->boolean('consent_publish')->default(false);
            $t->string('license_offered', 32)->nullable();    // cc_by, cc_by_sa, permission_granted ...
            $t->boolean('is_own_work')->default(false);
            // submitted, ai_checked, moderator_checked, expert_verified, published, rejected
            $t->string('status', 24)->default('submitted')->index();
            $t->json('ai_check')->nullable();
            $t->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('expert_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('review_notes')->nullable();
            $t->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $t->nullableMorphs('result');
            $t->char('ip_hash', 64)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('museum_quality_snapshots', function (Blueprint $t) {
            $t->id();
            $t->json('metrics');
            $t->timestamp('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE museum_search_documents ADD FULLTEXT ft_museum_search (normalized_text) WITH PARSER ngram');
        }
    }

    public function down(): void
    {
        foreach (['museum_quality_snapshots', 'museum_community_submissions', 'museum_rag_queries',
            'museum_embeddings', 'museum_text_chunks', 'museum_search_documents', 'museum_research_tasks',
            'museum_duplicate_candidates', 'museum_extraction_candidates', 'museum_extraction_jobs',
            'museum_ai_jobs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
