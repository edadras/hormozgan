<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sources, acquisition pipeline and raw storage. Created before the knowledge
 * tables because source extracts and facts reference them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('museum_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_type', 32)->index();
            $table->string('title', 1000);
            $table->string('title_original', 1000)->nullable();
            $table->string('author', 1000)->nullable();
            $table->string('publisher')->nullable();
            $table->string('publication_date', 32)->nullable();       // ISO-ish, may be partial: "1908", "1976-11"
            $table->string('publication_date_precision', 8)->nullable(); // year, month, day, decade, circa
            $table->string('url', 2048)->nullable();
            $table->string('url_hash', 64)->nullable()->index();
            $table->string('container_title', 1000)->nullable();     // book_name / journal / series
            $table->string('volume', 64)->nullable();
            $table->string('edition', 64)->nullable();
            $table->string('isbn', 32)->nullable()->index();
            $table->string('doi')->nullable()->index();
            $table->string('archive_reference')->nullable();
            $table->string('language', 16)->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->string('license', 32)->default('unknown');
            $table->string('copyright_status', 32)->default('unknown');
            $table->text('rights_statement')->nullable();
            $table->unsignedTinyInteger('reliability_tier')->default(3); // 1 (weak) .. 5 (authoritative)
            $table->string('crawl_policy', 24)->default('pending_review'); // allowed, metadata_only, forbidden, pending_review
            $table->string('verification_status', 24)->default('unverified')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->char('phase', 1)->nullable()->index();            // A..F collection phase
            $table->unsignedTinyInteger('priority')->default(3);
            $table->json('topics')->nullable();
            $table->json('regions')->nullable();
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('museum_crawler_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->string('name');
            // http_document, http_listing, sitemap, wikidata_sparql, iiif_manifest, local_files, api
            $table->string('kind', 32);
            $table->string('base_url', 2048)->nullable();
            $table->json('seed_urls')->nullable();
            $table->json('allowed_patterns')->nullable();
            $table->json('config')->nullable();
            $table->string('terms_url', 2048)->nullable();
            $table->boolean('terms_reviewed')->default(false);
            $table->foreignId('terms_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('robots_allowed')->nullable();
            $table->timestamp('robots_checked_at')->nullable();
            $table->unsignedInteger('crawl_delay_ms')->default(5000);
            $table->unsignedInteger('max_documents')->default(1000);
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('priority')->default(3);
            $table->string('schedule', 64)->nullable(); // cron expression
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('museum_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('importer', 64);           // wikidata_geography, source_registry, csv_words ...
            $table->string('status', 24)->default('pending')->index(); // pending, running, completed, failed
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->string('input_reference', 2048)->nullable();
            $table->json('options')->nullable();
            // Import report counters (section 61)
            $table->unsignedInteger('sources_found')->default(0);
            $table->unsignedInteger('sources_accepted')->default(0);
            $table->unsignedInteger('sources_rejected')->default(0);
            $table->unsignedInteger('documents_processed')->default(0);
            $table->unsignedInteger('facts_extracted')->default(0);
            $table->unsignedInteger('entities_created')->default(0);
            $table->unsignedInteger('entities_matched')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('conflicts')->default(0);
            $table->unsignedInteger('low_confidence')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->json('error_log')->nullable();
            $table->json('report')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('museum_crawler_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawler_source_id')->constrained('museum_crawler_sources')->cascadeOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained('museum_import_batches')->nullOnDelete();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('documents_fetched')->default(0);
            $table->unsignedInteger('documents_skipped')->default(0);
            $table->unsignedInteger('documents_unchanged')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->json('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Raw data is never deleted (Preservation). The model refuses delete().
        Schema::create('museum_raw_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->foreignId('crawler_source_id')->nullable()->constrained('museum_crawler_sources')->nullOnDelete();
            $table->foreignId('crawler_job_id')->nullable()->constrained('museum_crawler_jobs')->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained('museum_import_batches')->nullOnDelete();
            $table->string('url', 2048)->nullable();
            $table->string('url_hash', 64)->nullable()->index();
            $table->string('final_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->string('disk', 32);
            $table->string('path', 1024);
            $table->char('sha256', 64)->index();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->timestamp('fetched_at')->nullable();
            // fetched, text_extracted, ocr_pending, chunked, extracted, failed
            $table->string('status', 24)->default('fetched')->index();
            $table->string('text_path', 1024)->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->string('license', 32)->default('unknown');
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['museum_raw_documents', 'museum_crawler_jobs', 'museum_import_batches',
            'museum_crawler_sources', 'museum_sources'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
