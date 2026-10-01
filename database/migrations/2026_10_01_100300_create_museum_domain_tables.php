<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain extensions (1:1 with museum_entities) and domain records:
 * language atlas, oral history, media subtypes, culture, nature, maritime, people, history.
 *
 * Extension tables hold identity/structural attributes only; descriptive claims
 * are stored as sourced facts (see docs/museum/ARCHITECTURE.md §2.1).
 */
return new class extends Migration
{
    private function extension(string $name, callable $columns): void
    {
        Schema::create($name, function (Blueprint $table) use ($columns) {
            $table->foreignId('entity_id')->primary()->constrained('museum_entities')->cascadeOnDelete();
            $columns($table);
            $table->timestamps();
        });
    }

    public function up(): void
    {
        // ---------------------------------------------------------------- Language
        $this->extension('museum_dialects', function (Blueprint $t) {
            $t->foreignId('parent_dialect_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->string('level', 16)->default('dialect');      // language, dialect_group, dialect, subdialect
            $t->string('iso639_3', 8)->nullable();
            $t->string('glottocode', 16)->nullable();
        });

        Schema::create('museum_dialect_areas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dialect_id')->constrained('museum_entities')->cascadeOnDelete();
            $t->foreignId('place_id')->constrained('museum_entities')->cascadeOnDelete();
            $t->string('coverage', 16)->default('primary');     // primary, secondary, historical
            $t->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $t->string('page_number', 32)->nullable();
            $t->string('verification_status', 24)->default('unverified');
            $t->timestamps();
            $t->unique(['dialect_id', 'place_id']);
        });

        Schema::create('museum_concepts', function (Blueprint $t) {
            $t->id();
            $t->string('key', 96)->unique();                  // water, mother, boat ...
            $t->string('gloss_fa');
            $t->string('gloss_en');
            $t->string('semantic_field', 48)->nullable()->index();
            $t->string('concepticon_id', 16)->nullable();
            $t->timestamps();
        });

        $this->extension('museum_words', function (Blueprint $t) {
            $t->string('headword', 255);
            $t->string('normalized_headword', 255)->index();
            $t->string('transcription_fa', 255)->nullable(); // Persian-script phonetic transcription
            $t->string('transliteration', 255)->nullable();  // Latin transliteration
            $t->string('ipa', 255)->nullable();
            $t->string('part_of_speech', 24)->nullable()->index();
            $t->foreignId('dialect_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('concept_id')->nullable()->constrained('museum_concepts')->nullOnDelete();
            $t->text('etymology')->nullable();               // short editorial text; must be cited
            $t->text('usage_notes')->nullable();
            $t->text('historical_usage')->nullable();
            $t->string('register', 24)->nullable();          // everyday, archaic, maritime, agricultural ...
            $t->index(['concept_id', 'dialect_id']);
        });

        Schema::create('museum_word_meanings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('word_id')->constrained('museum_words', 'entity_id')->cascadeOnDelete();
            $t->unsignedTinyInteger('sense_no')->default(1);
            $t->text('meaning_fa');
            $t->text('meaning_en')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $t->string('page_number', 32)->nullable();
            $t->string('verification_status', 24)->default('unverified');
            $t->timestamps();
        });

        Schema::create('museum_audio_recordings', function (Blueprint $t) {
            $t->foreignId('media_id')->primary()->constrained('museum_media')->cascadeOnDelete();
            $t->foreignId('speaker_id')->nullable()->constrained('museum_speakers')->nullOnDelete();
            $t->date('recorded_on')->nullable();
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('dialect_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->string('recorded_by')->nullable();
            $t->string('equipment')->nullable();
            // Synthetic (TTS) audio may never be used as pronunciation evidence.
            $t->boolean('is_synthetic')->default(false);
            $t->text('transcript')->nullable();
            $t->timestamps();
        });

        Schema::create('museum_word_pronunciations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('word_id')->constrained('museum_words', 'entity_id')->cascadeOnDelete();
            $t->foreignId('audio_media_id')->nullable()->constrained('museum_audio_recordings', 'media_id')->nullOnDelete();
            $t->foreignId('speaker_id')->nullable()->constrained('museum_speakers')->nullOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->string('ipa', 255)->nullable();
            $t->string('transcription_fa', 255)->nullable();
            $t->text('notes')->nullable();
            $t->string('verification_status', 24)->default('unverified');
            $t->timestamps();
        });

        Schema::create('museum_sentences', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->text('text_local');
            $t->text('normalized_text');
            $t->text('transcription_fa')->nullable();
            $t->text('ipa')->nullable();
            $t->text('text_fa')->nullable();                // Standard Persian
            $t->text('text_en')->nullable();
            $t->foreignId('category_id')->nullable()->constrained('museum_categories')->nullOnDelete();
            $t->foreignId('dialect_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('speaker_id')->nullable()->constrained('museum_speakers')->nullOnDelete();
            $t->foreignId('audio_media_id')->nullable()->constrained('museum_audio_recordings', 'media_id')->nullOnDelete();
            $t->string('verification_status', 24)->default('unverified')->index();
            $t->string('visibility', 16)->default('draft')->index();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['dialect_id', 'category_id']);
        });

        Schema::create('museum_word_examples', function (Blueprint $t) {
            $t->id();
            $t->foreignId('word_id')->constrained('museum_words', 'entity_id')->cascadeOnDelete();
            $t->foreignId('sentence_id')->constrained('museum_sentences')->cascadeOnDelete();
            $t->unsignedTinyInteger('sort')->default(0);
            $t->unique(['word_id', 'sentence_id']);
        });

        Schema::create('museum_word_relations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('word_id')->constrained('museum_words', 'entity_id')->cascadeOnDelete();
            $t->foreignId('related_word_id')->constrained('museum_words', 'entity_id')->cascadeOnDelete();
            $t->string('relation', 24);                      // synonym, antonym, variant, derived_from, cognate, compound_of
            $t->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $t->timestamps();
            $t->unique(['word_id', 'related_word_id', 'relation']);
        });

        Schema::create('museum_grammar_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('dialect_id')->constrained('museum_entities')->cascadeOnDelete();
            // phonology, pronunciation, transcription, pronouns, nouns, pluralization, adjectives, possession,
            // verbs, conjugation, present, past, future, negation, questions, imperative, prepositions, syntax
            $t->string('topic', 32)->index();
            $t->string('title', 500);
            $t->text('description');
            $t->json('paradigm')->nullable();                // tabular data, e.g. pronoun table
            $t->string('verification_status', 24)->default('unverified')->index();
            $t->string('visibility', 16)->default('draft');
            $t->unsignedSmallInteger('sort')->default(100);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('museum_grammar_examples', function (Blueprint $t) {
            $t->id();
            $t->foreignId('grammar_rule_id')->constrained('museum_grammar_rules')->cascadeOnDelete();
            $t->foreignId('sentence_id')->nullable()->constrained('museum_sentences')->nullOnDelete();
            $t->text('text_local')->nullable();
            $t->text('gloss')->nullable();                   // interlinear gloss
            $t->text('translation_fa')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        $this->extension('museum_proverbs', function (Blueprint $t) {
            $t->string('kind', 16)->default('proverb');     // proverb, idiom, expression
            $t->text('text_local');
            $t->text('normalized_text');
            $t->text('transcription_fa')->nullable();
            $t->text('ipa')->nullable();
            $t->text('literal_meaning')->nullable();
            $t->text('figurative_meaning')->nullable();
            $t->text('usage_context')->nullable();
            $t->text('backstory')->nullable();
            $t->text('persian_equivalent')->nullable();
            $t->foreignId('dialect_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('audio_media_id')->nullable()->constrained('museum_audio_recordings', 'media_id')->nullOnDelete();
        });

        // ---------------------------------------------------------------- Media subtypes & archive
        Schema::create('museum_photos', function (Blueprint $t) {
            $t->foreignId('media_id')->primary()->constrained('museum_media')->cascadeOnDelete();
            $t->string('photographer')->nullable();
            $t->foreignId('depicted_place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->text('current_location_note')->nullable();
            $t->decimal('view_latitude', 10, 7)->nullable();
            $t->decimal('view_longitude', 10, 7)->nullable();
            $t->decimal('view_bearing', 5, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('museum_photo_pairs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('then_media_id')->constrained('museum_media')->cascadeOnDelete();
            $t->foreignId('now_media_id')->constrained('museum_media')->cascadeOnDelete();
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->text('note')->nullable();
            $t->string('visibility', 16)->default('draft');
            $t->timestamps();
        });

        Schema::create('museum_videos', function (Blueprint $t) {
            $t->foreignId('media_id')->primary()->constrained('museum_media')->cascadeOnDelete();
            $t->string('video_kind', 24)->nullable();        // documentary, tutorial, interview, performance, archival
            $t->text('transcript')->nullable();
            $t->string('poster_path', 1024)->nullable();
            $t->timestamps();
        });

        $this->extension('museum_interviews', function (Blueprint $t) {
            $t->foreignId('speaker_id')->nullable()->constrained('museum_speakers')->nullOnDelete();
            $t->string('interviewer')->nullable();
            $t->date('recorded_on')->nullable();
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('dialect_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->foreignId('audio_media_id')->nullable()->constrained('museum_media')->nullOnDelete();
            $t->foreignId('video_media_id')->nullable()->constrained('museum_media')->nullOnDelete();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('language', 16)->nullable();
            $t->json('topics')->nullable();
            $t->string('consent_status', 24)->default('pending');
            $t->foreignId('consent_media_id')->nullable()->constrained('museum_media')->nullOnDelete();
            $t->string('license', 32)->default('restricted');
            $t->string('copyright_holder')->nullable();
            $t->string('transcript_status', 24)->default('none'); // none, ai_draft, corrected, verified
        });

        Schema::create('museum_interview_segments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('interview_id')->constrained('museum_interviews', 'entity_id')->cascadeOnDelete();
            $t->unsignedInteger('seq');
            $t->unsignedInteger('start_ms');
            $t->unsignedInteger('end_ms');
            $t->string('speaker_label', 64)->nullable();
            $t->text('text_original');                       // as transcribed (AI or human) — preserved
            $t->text('text_corrected')->nullable();
            $t->text('text_fa')->nullable();                 // standard Persian rendering
            $t->boolean('is_ai_generated')->default(false);
            $t->string('verification_status', 24)->default('unverified');
            $t->timestamps();
            $t->unique(['interview_id', 'seq']);
        });

        Schema::create('museum_maps', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('document_id')->nullable()->constrained('museum_documents', 'entity_id')->nullOnDelete();
            $t->foreignId('media_id')->nullable()->constrained('museum_media')->nullOnDelete();
            $t->string('title', 500);
            $t->smallInteger('year')->nullable();
            $t->string('year_precision', 8)->nullable();
            $t->string('cartographer')->nullable();
            $t->string('scale')->nullable();
            $t->decimal('north', 10, 7)->nullable();
            $t->decimal('south', 10, 7)->nullable();
            $t->decimal('east', 10, 7)->nullable();
            $t->decimal('west', 10, 7)->nullable();
            $t->string('tile_url', 2048)->nullable();        // georeferenced XYZ tiles if available
            $t->boolean('is_georeferenced')->default(false);
            $t->foreignId('source_id')->nullable()->constrained('museum_sources')->nullOnDelete();
            $t->string('license', 32)->default('unknown');
            $t->string('visibility', 16)->default('draft');
            $t->timestamps();
            $t->index('year');
        });

        // ---------------------------------------------------------------- Culture
        $this->extension('museum_foods', function (Blueprint $t) {
            $t->foreignId('category_id')->nullable()->constrained('museum_categories')->nullOnDelete();
            $t->string('local_pronunciation', 255)->nullable();
            $t->boolean('is_ceremonial')->default(false);
        });

        Schema::create('museum_food_ingredients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('food_id')->constrained('museum_foods', 'entity_id')->cascadeOnDelete();
            $t->foreignId('ingredient_entity_id')->nullable()->constrained('museum_entities')->nullOnDelete();
            $t->string('name', 255);
            $t->string('local_name', 255)->nullable();
            $t->string('quantity', 64)->nullable();
            $t->string('unit', 32)->nullable();
            $t->boolean('is_optional')->default(false);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('museum_recipes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('food_id')->constrained('museum_foods', 'entity_id')->cascadeOnDelete();
            $t->string('title', 500);
            $t->foreignId('place_id')->nullable()->constrained('museum_entities')->nullOnDelete(); // regional variant
            $t->foreignId('speaker_id')->nullable()->constrained('museum_speakers')->nullOnDelete(); // family recipe holder
            $t->json('steps');                               // ordered list of strings
            $t->string('servings', 32)->nullable();
            $t->unsignedSmallInteger('time_minutes')->nullable();
            $t->string('verification_status', 24)->default('unverified');
            $t->string('visibility', 16)->default('draft');
            $t->timestamps();
            $t->softDeletes();
        });

        $this->extension('museum_traditions', function (Blueprint $t) {
            $t->foreignId('category_id')->nullable()->constrained('museum_categories')->nullOnDelete();
            $t->string('calendar_timing')->nullable();       // e.g. "Nowruz", "harvest season" — as stated in sources
        });

        $this->extension('museum_clothing_items', function (Blueprint $t) {
            $t->foreignId('parent_item_id')->nullable()->constrained('museum_entities')->nullOnDelete(); // Dress → Sleeve → Embroidery
            $t->string('component_type', 24)->default('garment'); // garment, component, embroidery, fabric, ornament, accessory
            $t->string('wearer', 16)->nullable();            // women, men, children, unisex
            $t->string('occasion', 16)->nullable();          // daily, wedding, ceremonial, work
            $t->boolean('is_historical')->default(false);
            $t->foreignId('category_id')->nullable()->constrained('museum_categories')->nullOnDelete();
        });

        $this->extension('museum_crafts', function (Blueprint $t) {
            $t->foreignId('category_id')->nullable()->constrained('museum_categories')->nullOnDelete();
            $t->boolean('unesco_listed')->default(false);
        });

        $this->extension('museum_games', function (Blueprint $t) {
            $t->unsignedTinyInteger('players_min')->nullable();
            $t->unsignedTinyInteger('players_max')->nullable();
            $t->unsignedTinyInteger('age_min')->nullable();
            $t->unsignedTinyInteger('age_max')->nullable();
            $t->string('setting', 16)->nullable();           // indoor, outdoor, beach, any
        });

        $this->extension('museum_music_items', function (Blueprint $t) {
            $t->string('music_kind', 16);                    // instrument, song, genre, ensemble
            $t->foreignId('category_id')->nullable()->constrained('museum_categories')->nullOnDelete();
            $t->boolean('lyrics_publishable')->default(false);
            $t->text('lyrics')->nullable();                  // only when legally permitted
        });

        // ---------------------------------------------------------------- Nature
        $this->extension('museum_plants', function (Blueprint $t) {
            $t->string('scientific_name', 255)->nullable()->index();
            $t->string('family', 128)->nullable()->index();
            $t->string('plant_kind', 24)->nullable();        // tree, palm, shrub, herb, crop, grass, mangrove, marine
            $t->boolean('is_cultivated')->nullable();
            $t->boolean('is_native')->nullable();
        });

        $this->extension('museum_plant_varieties', function (Blueprint $t) {
            $t->foreignId('plant_id')->constrained('museum_plants', 'entity_id')->cascadeOnDelete();
            $t->string('cultivar_code', 64)->nullable();
            $t->string('sex', 8)->nullable();               // male, female (date palm)
        });

        // ---------------------------------------------------------------- People & history
        $this->extension('museum_people', function (Blueprint $t) {
            $t->smallInteger('birth_year')->nullable();
            $t->string('birth_date', 16)->nullable();
            $t->smallInteger('death_year')->nullable();
            $t->string('death_date', 16)->nullable();
            $t->string('date_precision', 8)->nullable();
            $t->boolean('is_living')->nullable();           // living people: only public-role information
            $t->string('privacy_level', 16)->default('public_figure'); // public_figure, limited, private
        });

        $this->extension('museum_historical_events', function (Blueprint $t) {
            $t->string('event_type', 32)->nullable()->index(); // war, trade, natural_disaster, founding, political, cultural
            $t->smallInteger('year_from')->nullable();
            $t->unsignedTinyInteger('month_from')->nullable();
            $t->unsignedTinyInteger('day_from')->nullable();
            $t->smallInteger('year_to')->nullable();
            $t->string('date_precision', 8)->nullable();     // day, month, year, decade, century, circa
            $t->string('date_original', 128)->nullable();    // as stated in the source (e.g. Hijri date)
            $t->index(['year_from', 'year_to']);
        });

        // Interactive diagrams: lenj parts, clothing components ...
        Schema::create('museum_diagrams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entity_id')->constrained('museum_entities')->cascadeOnDelete();
            $t->foreignId('media_id')->nullable()->constrained('museum_media')->nullOnDelete();
            $t->string('title', 500);
            $t->string('kind', 16)->default('image');        // image, svg, model3d
            $t->string('viewbox', 64)->nullable();
            $t->string('visibility', 16)->default('draft');
            $t->timestamps();
        });

        Schema::create('museum_diagram_hotspots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('diagram_id')->constrained('museum_diagrams')->cascadeOnDelete();
            $t->foreignId('part_entity_id')->constrained('museum_entities')->cascadeOnDelete();
            $t->string('shape', 16)->default('polygon');     // polygon, circle, rect, mesh
            $t->text('coordinates');                         // SVG points / mesh name
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE museum_sentences ADD FULLTEXT ft_museum_sentences (normalized_text, text_fa) WITH PARSER ngram');
        }
    }

    public function down(): void
    {
        foreach (['museum_diagram_hotspots', 'museum_diagrams', 'museum_historical_events', 'museum_people',
            'museum_plant_varieties', 'museum_plants', 'museum_music_items', 'museum_games', 'museum_crafts',
            'museum_clothing_items', 'museum_traditions', 'museum_recipes', 'museum_food_ingredients',
            'museum_foods', 'museum_maps', 'museum_interview_segments', 'museum_interviews', 'museum_videos',
            'museum_photo_pairs', 'museum_photos', 'museum_proverbs', 'museum_grammar_examples',
            'museum_grammar_rules', 'museum_word_relations', 'museum_word_examples', 'museum_sentences',
            'museum_word_pronunciations', 'museum_audio_recordings', 'museum_word_meanings', 'museum_words',
            'museum_concepts', 'museum_dialect_areas', 'museum_dialects'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
