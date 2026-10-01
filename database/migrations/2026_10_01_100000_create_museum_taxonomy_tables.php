<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxonomy and governance tables for the museum module: entity types,
 * relationship types, the fact property registry, categories, tags and RBAC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('museum_entity_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name_fa');
            $table->string('name_en');
            $table->foreignId('parent_id')->nullable()->constrained('museum_entity_types')->nullOnDelete();
            // Domain extension table, e.g. "museum_places". Null when the type has no extension.
            $table->string('extension_table', 64)->nullable();
            $table->string('domain', 32)->index(); // geography, language, history, culture, nature, maritime, archive, people
            $table->string('icon', 32)->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('museum_relationship_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();          // belongs_to, used_in, cultivated_in ...
            $table->string('name_fa');
            $table->string('name_en');
            $table->string('inverse_key', 64)->nullable();
            $table->string('inverse_name_fa')->nullable();
            $table->string('inverse_name_en')->nullable();
            $table->json('subject_types')->nullable();    // allowed entity type keys, null = any
            $table->json('object_types')->nullable();
            $table->boolean('is_symmetric')->default(false);
            $table->timestamps();
        });

        Schema::create('museum_properties', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96)->unique();          // population, etymology, founding_year ...
            $table->string('label_fa');
            $table->string('label_en');
            // string, text, integer, decimal, year, date, boolean, entity, geo, json
            $table->string('datatype', 16);
            $table->json('entity_types')->nullable();     // applicable entity type keys, null = any
            $table->boolean('is_multivalued')->default(false); // multi-valued properties never conflict
            // When the property points to an entity, the relationship type it materializes.
            $table->foreignId('relationship_type_id')->nullable()->constrained('museum_relationship_types')->nullOnDelete();
            $table->json('qualifier_keys')->nullable();   // e.g. ["census_year"]; part of the conflict key
            $table->string('unit', 32)->nullable();
            $table->string('group', 48)->nullable();      // UI grouping: history, language, economy ...
            $table->unsignedSmallInteger('sort')->default(100);
            $table->string('wikidata_pid', 16)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('museum_categories', function (Blueprint $table) {
            $table->id();
            $table->string('scheme', 48);                 // food, sentence, tradition, clothing, music, grammar, document ...
            $table->string('key', 64);
            $table->string('name_fa');
            $table->string('name_en');
            $table->foreignId('parent_id')->nullable()->constrained('museum_categories')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
            $table->unique(['scheme', 'key']);
        });

        Schema::create('museum_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('museum_taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained('museum_tags')->cascadeOnDelete();
            $table->morphs('taggable');
            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
        });

        Schema::create('museum_roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 48)->unique();
            $table->string('name_fa');
            $table->string('name_en');
            $table->timestamps();
        });

        Schema::create('museum_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('museum_permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('museum_permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('museum_roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('museum_role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('museum_roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['museum_role_user', 'museum_permission_role', 'museum_permissions', 'museum_roles',
            'museum_taggables', 'museum_tags', 'museum_categories', 'museum_properties',
            'museum_relationship_types', 'museum_entity_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
