<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge core: entities, aliases, historical names, media, documents,
 * source extracts, facts (+ values, sources, revisions, conflicts), graph
 * edges, citations, mentions and verification logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('museum_entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('entity_type_id')->constrained('museum_entity_types');
            $table->string('slug', 191)->unique();
            $table->string('canonical_name', 500);
            $table->string('normalized_name', 500)->index();
            $table->string('name_fa', 500)->nullable();
            $table->string('name_en', 500)->nullable();
            $table->string('name_ar', 500)->nullable();
            $table->string('name_local', 500)->nullable();      // in local dialect, Perso-Arabic script
            $table->string('name_local_latin', 500)->nullable(); // transliteration
            $table->text('summary_fa')->nullable();             // editorial summary; descriptive claims live in facts
            $table->text('summary_en')->nullable();
            $table->foreignId('primary_place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('verification_status', 24)->default('unverified')->index();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('visibility', 16)->default('draft')->index(); // draft, published, hidden, restricted
            $table->boolean('is_candidate')->default(false)->index();   // discovered, awaiting evidence
            $table->foreignId('merged_into_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $table->string('wikidata_id', 16)->nullable()->unique();
            $table->json('external_ids')->nullable();
            $table->unsignedInteger('facts_count')->default(0);
            $table->unsignedInteger('sources_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['entity_type_id', 'visibility', 'id']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('museum_entity_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('museum_entities')->cascadeOnDelete();
            $table->string('alias', 500);
            $table->string('normalized_alias', 500)->index();
            $table->string('compact_key', 500)->index();        // normalized without spaces
            $table->string('language', 16)->nullable();          // fa, en, ar, local, ...
            $table->string('script', 16)->nullable();            // Arab, Latn
            // canonical, local, transliteration, spelling_variant, historical, abbreviation, colloquial
            $table->string('alias_type', 24)->default('spelling_variant');
            $table->boolean('is_primary')->default(false);
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->timestamps();
            $table->unique(['entity_id', 'normalized_alias', 'language']);
        });

        Schema::create('museum_historical_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('museum_entities')->cascadeOnDelete();
            $table->string('name', 500);
            $table->string('normalized_name', 500)->index();
            $table->string('local_pronunciation', 500)->nullable();
            $table->string('ipa', 500)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('period_label')->nullable();          // e.g. "Safavid", "قاجار"
            $table->smallInteger('year_from')->nullable();       // CE; negative for BCE
            $table->smallInteger('year_to')->nullable();
            $table->string('year_precision', 8)->nullable();
            $table->text('meaning')->nullable();
            $table->text('naming_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->unsignedBigInteger('source_extract_id')->nullable()->index();
            $table->string('page_number', 32)->nullable();
            $table->string('verification_status', 24)->default('unverified')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('museum_speakers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('display_name')->nullable();          // public name only if publish_name
            $table->boolean('publish_name')->default(false);
            $table->string('gender', 16)->nullable();            // female, male, other, undisclosed
            $table->smallInteger('birth_year')->nullable();
            $table->string('age_group', 16)->nullable();         // child, youth, adult, elder
            $table->foreignId('home_place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $table->foreignId('dialect_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $table->string('occupation')->nullable();
            // pending, granted, granted_restricted, withdrawn, none
            $table->string('consent_status', 24)->default('pending')->index();
            $table->json('consent_scope')->nullable();           // {"publish_audio":true,"publish_name":false,...}
            $table->date('consent_date')->nullable();
            $table->unsignedBigInteger('consent_media_id')->nullable();
            $table->text('private_contact')->nullable();         // encrypted cast; never exposed publicly
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('museum_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('media_type', 16)->index();           // image, audio, video, document, map, model3d
            $table->string('disk', 32);
            $table->string('path', 1024);
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64)->nullable()->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('title', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('creator')->nullable();               // photographer / performer / recordist
            $table->smallInteger('year')->nullable();
            $table->string('year_precision', 8)->nullable();     // exact, circa, decade, unknown
            $table->date('captured_on')->nullable();
            $table->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $table->string('license', 32)->default('unknown')->index();
            $table->string('copyright_status', 32)->default('unknown');
            $table->string('copyright_holder')->nullable();
            $table->text('rights_statement')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->string('processing_status', 24)->default('pending'); // pending, processed, failed
            $table->json('variants')->nullable();                // thumbnails, webp, waveform
            $table->string('verification_status', 24)->default('unverified')->index();
            $table->string('visibility', 16)->default('draft')->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('museum_mediables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('museum_media')->cascadeOnDelete();
            $table->morphs('mediable');
            $table->string('role', 24)->default('gallery');      // primary, gallery, pronunciation, consent, then, now, tutorial
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unique(['media_id', 'mediable_type', 'mediable_id', 'role'], 'museum_mediables_unique');
        });

        Schema::create('museum_documents', function (Blueprint $table) {
            $table->foreignId('entity_id')->primary()->constrained('museum_entities')->cascadeOnDelete();
            // map, letter, newspaper, book, government_document, travel_record, photo, article, thesis, report
            $table->string('document_type', 32)->index();
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->foreignId('raw_document_id')->nullable()->constrained('museum_raw_documents')->nullOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('museum_media')->nullOnDelete();
            $table->string('author')->nullable();
            $table->string('date_text', 64)->nullable();         // as written on the document
            $table->smallInteger('year')->nullable();
            $table->string('year_precision', 8)->nullable();
            $table->string('language', 16)->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->string('ocr_status', 24)->default('none');   // none, pending, done, verified
            $table->timestamps();
        });

        Schema::create('museum_document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('museum_documents', 'entity_id')->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('page_label', 32)->nullable();        // printed page label, e.g. "xiv" or "۱۲"
            $table->foreignId('media_id')->nullable()->constrained('museum_media')->nullOnDelete();
            $table->longText('ocr_text_raw')->nullable();        // original OCR, never overwritten
            $table->string('ocr_engine', 64)->nullable();
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->longText('cleaned_text')->nullable();        // AI cleanup, kept separately
            $table->longText('corrected_text')->nullable();      // human-verified text
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->string('status', 24)->default('pending');    // pending, ocr_done, ai_cleaned, verified
            $table->timestamps();
            $table->unique(['document_id', 'page_number']);
        });

        // Fact → Source → Page → Extract
        Schema::create('museum_source_extracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('museum_sources');
            $table->foreignId('raw_document_id')->nullable()->constrained('museum_raw_documents')->nullOnDelete();
            $table->foreignId('document_page_id')->nullable()->constrained('museum_document_pages')->nullOnDelete();
            $table->string('page_number', 32)->nullable();
            $table->string('locator')->nullable();               // "§ Bandar Abbas", "00:12:31", "Q1234#P1082"
            $table->longText('original_text');
            $table->longText('normalized_text')->nullable();
            $table->string('language', 16)->nullable();
            $table->unsignedInteger('char_start')->nullable();
            $table->unsignedInteger('char_end')->nullable();
            $table->char('text_hash', 64)->index();
            $table->string('extracted_by', 16)->default('human'); // human, ai, ocr, import
            $table->timestamps();
            $table->index(['source_id', 'page_number']);
        });

        Schema::create('museum_fact_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('museum_entities')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('museum_properties');
            $table->string('conflict_key', 191)->index();        // entity|property|scope|qualifiers hash
            $table->string('status', 24)->default('open')->index(); // open, resolved, acknowledged
            $table->string('resolution', 32)->nullable();        // preferred_selected, both_retained, merged
            $table->unsignedBigInteger('preferred_fact_id')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('museum_facts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('entity_id')->constrained('museum_entities')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('museum_properties');
            // string, text, integer, decimal, year, date, boolean, entity, geo, json, unknown
            $table->string('value_type', 16);
            $table->longText('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->smallInteger('value_year_from')->nullable();
            $table->smallInteger('value_year_to')->nullable();
            $table->string('value_date', 32)->nullable();
            $table->foreignId('value_entity_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $table->json('value_json')->nullable();
            $table->string('value_normalized', 500)->nullable();
            $table->char('value_hash', 64)->index();
            $table->boolean('is_unknown')->default(false);
            $table->string('language', 16)->nullable();
            $table->foreignId('scope_place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $table->json('qualifiers')->nullable();
            $table->string('conflict_key', 191)->index();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('verification_status', 24)->default('unverified')->index();
            $table->string('extraction_method', 24)->default('manual'); // manual, structured_import, ai, community, ocr
            $table->unsignedBigInteger('extraction_job_id')->nullable()->index();
            $table->foreignId('import_batch_id')->nullable()->constrained('museum_import_batches')->nullOnDelete();
            $table->foreignId('conflict_id')->nullable()->constrained('museum_fact_conflicts')->nullOnDelete();
            $table->boolean('is_preferred')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedSmallInteger('sources_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['entity_id', 'property_id', 'verification_status']);
            $table->index(['property_id', 'value_number']);
            $table->index(['value_entity_id', 'property_id']);
            $table->index(['verification_status', 'confidence_score']);
        });

        Schema::table('museum_fact_conflicts', function (Blueprint $table) {
            $table->foreign('preferred_fact_id')->references('id')->on('museum_facts')->nullOnDelete();
        });

        Schema::create('museum_fact_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fact_id')->constrained('museum_facts')->cascadeOnDelete();
            $table->string('locale', 16);
            $table->text('display_value');
            $table->timestamps();
            $table->unique(['fact_id', 'locale']);
        });

        Schema::create('museum_fact_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fact_id')->constrained('museum_facts')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('museum_sources');
            $table->foreignId('source_extract_id')->nullable()->constrained('museum_source_extracts')->nullOnDelete();
            $table->string('page_number', 32)->nullable();
            $table->string('locator')->nullable();
            $table->text('quote')->nullable();
            $table->string('support', 16)->default('supports');   // supports, contradicts, mentions
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('verification_status', 24)->default('unverified');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_id', 'fact_id']);
            $table->unique(['fact_id', 'source_id', 'source_extract_id'], 'museum_fact_sources_unique');
        });

        Schema::create('museum_fact_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fact_id')->constrained('museum_facts')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('change_type', 24);                    // created, value_changed, status_changed, source_added, restored
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24)->nullable();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['fact_id', 'revision']);
        });

        Schema::create('museum_entity_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('museum_entities')->cascadeOnDelete();
            $table->foreignId('relationship_type_id')->constrained('museum_relationship_types');
            $table->foreignId('object_id')->constrained('museum_entities')->cascadeOnDelete();
            // Every edge is backed by a fact, so its provenance is always traceable.
            $table->foreignId('fact_id')->unique()->constrained('museum_facts')->cascadeOnDelete();
            $table->smallInteger('valid_from_year')->nullable();
            $table->smallInteger('valid_to_year')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('verification_status', 24)->default('unverified');
            $table->timestamps();
            $table->index(['subject_id', 'relationship_type_id']);
            $table->index(['object_id', 'relationship_type_id']);
        });

        // Citations for non-fact records (words, rules, proverbs, recipes, media, sentences ...).
        Schema::create('museum_citations', function (Blueprint $table) {
            $table->id();
            $table->morphs('citable');
            $table->foreignId('source_id')->constrained('museum_sources');
            $table->foreignId('source_extract_id')->nullable()->constrained('museum_source_extracts')->nullOnDelete();
            $table->string('page_number', 32)->nullable();
            $table->string('locator')->nullable();
            $table->text('quote')->nullable();
            $table->string('role', 24)->default('evidence');      // evidence, origin, recording, consent
            $table->timestamps();
            $table->index(['source_id']);
        });

        Schema::create('museum_translations', function (Blueprint $table) {
            $table->id();
            $table->morphs('translatable');
            $table->string('field', 64);
            $table->string('locale', 16);
            $table->text('value');
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->string('verification_status', 24)->default('unverified');
            $table->timestamps();
            $table->unique(['translatable_type', 'translatable_id', 'field', 'locale'], 'museum_translations_unique');
        });

        // Links a span of text (interview segment, document page, extract) to an entity.
        Schema::create('museum_mentions', function (Blueprint $table) {
            $table->id();
            $table->morphs('mentionable');
            $table->foreignId('entity_id')->constrained('museum_entities')->cascadeOnDelete();
            $table->string('surface_form', 500);
            $table->unsignedInteger('char_start')->nullable();
            $table->unsignedInteger('char_end')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('status', 16)->default('suggested');   // suggested, confirmed, rejected
            $table->string('detected_by', 16)->default('gazetteer'); // gazetteer, ai, human
            $table->timestamps();
            $table->index(['entity_id', 'status']);
        });

        Schema::create('museum_entity_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merged_entity_id')->constrained('museum_entities');
            $table->foreignId('into_entity_id')->constrained('museum_entities');
            $table->json('snapshot');
            $table->text('reason')->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('museum_verification_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('verifiable');
            $table->string('action', 32);                         // status_change, approve, reject, merge, resolve_conflict, publish
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 32)->nullable();
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('museum_places', function (Blueprint $table) {
            $table->foreignId('entity_id')->primary()->constrained('museum_entities')->cascadeOnDelete();
            // province, county, district, city, rural_district, village, neighborhood, island, mountain,
            // river, port, bay, historical_site, building, market, street, qanat, natural_feature, region
            $table->string('place_type', 32)->index();
            $table->foreignId('parent_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $table->string('path', 255)->nullable()->index();     // materialized ancestry "/1/5/23/"
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('admin_code', 32)->nullable()->index(); // national statistical code when known
            $table->decimal('elevation_m', 8, 2)->nullable();
            $table->longText('geometry')->nullable();            // GeoJSON
            $table->boolean('is_historical')->default(false);
            $table->smallInteger('existed_from_year')->nullable();
            $table->smallInteger('existed_to_year')->nullable();
            $table->timestamps();
        });

        // Time-aware geometries for the "Hormozgan through time" map.
        Schema::create('museum_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->nullable()->constrained('museum_entities')->cascadeOnDelete();
            $table->string('feature_type', 32);                  // settlement, coastline, road, port, market, building, neighborhood
            $table->string('label', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->longText('geometry')->nullable();            // GeoJSON
            $table->smallInteger('year_from')->nullable();
            $table->smallInteger('year_to')->nullable();
            $table->string('precision', 16)->nullable();         // exact, approximate, schematic
            $table->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $table->foreignId('map_id')->nullable();
            $table->string('verification_status', 24)->default('unverified');
            $table->timestamps();
            $table->index(['year_from', 'year_to']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE museum_entities ADD FULLTEXT ft_museum_entities_names (canonical_name, name_fa, name_en, name_local) WITH PARSER ngram');
        }
    }

    public function down(): void
    {
        Schema::table('museum_fact_conflicts', function (Blueprint $table) {
            $table->dropForeign(['preferred_fact_id']);
        });
        foreach (['museum_locations', 'museum_places', 'museum_verification_logs', 'museum_entity_merges',
            'museum_mentions', 'museum_translations', 'museum_citations', 'museum_entity_relationships',
            'museum_fact_revisions', 'museum_fact_sources', 'museum_fact_values', 'museum_facts',
            'museum_fact_conflicts', 'museum_source_extracts', 'museum_document_pages', 'museum_documents',
            'museum_mediables', 'museum_media', 'museum_speakers', 'museum_historical_names',
            'museum_entity_aliases', 'museum_entities'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
