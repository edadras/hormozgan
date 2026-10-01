<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\EntityRelationship;
use App\Models\Museum\Fact;
use App\Models\Museum\FactConflict;
use App\Models\Museum\FactRevision;
use App\Models\Museum\RawDocument;
use App\Museum\Enums\VerificationStatus;
use App\Museum\Services\FactService;
use App\Museum\Services\SourceService;
use InvalidArgumentException;

class FactProvenanceTest extends MuseumTestCase
{
    private FactService $facts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->facts = app(FactService::class);
    }

    public function test_fact_traces_to_source_page_and_extract(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $source = $this->source(['title' => 'Fixture Gazetteer']);
        $extract = app(SourceService::class)->extract($source, 'Fixture Village was formerly called Oldname.', ['page' => '42']);

        $fact = $this->facts->assert($place, 'etymology', 'formerly called Oldname', [
            'source' => $source, 'extract' => $extract, 'quote' => 'formerly called Oldname',
            'status' => 'source_verified',
        ]);

        $fs = $fact->sources()->with('source', 'extract')->sole();
        $this->assertSame($source->id, $fs->source_id);
        $this->assertSame('42', $fs->page_number);
        $this->assertSame($extract->id, $fs->extract->id);
        $this->assertSame(1, $fact->sources_count);
        $this->assertSame('created', $fact->revisions()->first()->change_type);
    }

    public function test_cannot_verify_without_source(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $this->expectException(InvalidArgumentException::class);
        $this->facts->assert($place, 'founding_year', 1890, ['status' => 'source_verified']);
    }

    public function test_unknown_is_recorded_not_guessed(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $fact = $this->facts->assertUnknown($place, 'founding_year', ['source' => $this->source(), 'status' => 'source_verified']);

        $this->assertTrue($fact->is_unknown);
        $this->assertSame('unknown', $fact->value_type);
        $this->assertStringContainsString('UNKNOWN', $fact->displayValue());
        $this->assertNull($fact->value_year_from);
    }

    public function test_same_value_from_second_source_corroborates(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $a = $this->facts->assert($place, 'founding_year', 1890, ['source' => $this->source()]);
        $b = $this->facts->assert($place, 'founding_year', '۱۸۹۰', ['source' => $this->source()]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(2, $b->sources_count);
        $this->assertSame(1, Fact::count());
        $this->assertSame(0, FactConflict::count());
    }

    public function test_contradicting_sources_create_conflict_and_keep_both(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $a = $this->facts->assert($place, 'founding_year', 1890, ['source' => $this->source(), 'status' => 'source_verified']);
        $b = $this->facts->assert($place, 'founding_year', 1895, ['source' => $this->source(), 'status' => 'source_verified']);

        $conflict = FactConflict::sole();
        $this->assertSame('open', $conflict->status);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $conflict->facts()->pluck('id')->all());
        // Nothing deleted, verification levels untouched.
        $this->assertSame('source_verified', $a->fresh()->verification_status);
        $this->assertSame('source_verified', $b->fresh()->verification_status);

        $this->facts->resolveConflict($conflict, $b->id, 'preferred_selected', 'Primary source is more reliable', null);
        $this->assertTrue($b->fresh()->is_preferred);
        $this->assertFalse($a->fresh()->is_preferred);
        $this->assertSame(2, Fact::count());
    }

    public function test_population_in_different_census_years_does_not_conflict(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $this->facts->assert($place, 'population', 120, ['source' => $this->source(), 'qualifiers' => ['census_year' => 1976]]);
        $this->facts->assert($place, 'population', 300, ['source' => $this->source(), 'qualifiers' => ['census_year' => 2016]]);
        $this->assertSame(0, FactConflict::count());
        $this->assertSame(2, Fact::count());
    }

    public function test_multivalued_property_never_conflicts(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $this->facts->assert($place, 'description', 'Text A', ['source' => $this->source()]);
        $this->facts->assert($place, 'description', 'Text B', ['source' => $this->source()]);
        $this->assertSame(0, FactConflict::count());
    }

    public function test_value_edit_keeps_history(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $fact = $this->facts->assert($place, 'founding_year', 1890, ['source' => $this->source()]);
        $this->facts->updateValue($fact, 1891, null, 'Typo in transcription', $this->source());

        $rev = FactRevision::where('fact_id', $fact->id)->where('change_type', 'value_changed')->sole();
        $this->assertSame(1890, $rev->previous_value['value_year_from']);
        $this->assertSame(1891, $rev->new_value['value_year_from']);
        $this->assertSame('Typo in transcription', $rev->reason);
        $this->assertNotNull($rev->source_id);
    }

    public function test_value_edit_requires_reason(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $fact = $this->facts->assert($place, 'founding_year', 1890, ['source' => $this->source()]);
        $this->expectException(InvalidArgumentException::class);
        $this->facts->updateValue($fact, 1891, null, '');
    }

    public function test_entity_fact_materializes_graph_edge(): void
    {
        $county = $this->entity('county', 'Fixture County');
        $village = $this->entity('village', 'Fixture Village');
        $fact = $this->facts->assert($village, 'located_in', $county, ['source' => $this->source()]);

        $edge = EntityRelationship::sole();
        $this->assertSame($fact->id, $edge->fact_id);
        $this->assertSame($village->id, $edge->subject_id);
        $this->assertSame($county->id, $edge->object_id);
        $this->assertSame('located_in', $edge->type->key);
    }

    public function test_property_type_scope_is_enforced(): void
    {
        $word = $this->entity('word', 'fixtureword', [], ['headword' => 'fixtureword', 'normalized_headword' => 'fixtureword']);
        $this->expectException(InvalidArgumentException::class);
        $this->facts->assert($word, 'birth_year', 1900);
    }

    public function test_status_change_is_logged_and_needs_source(): void
    {
        $place = $this->entity('village', 'Fixture Village');
        $fact = $this->facts->assert($place, 'founding_year', 1890, ['status' => 'ai_extracted']);
        try {
            $this->facts->changeStatus($fact, VerificationStatus::ExpertVerified, null);
            $this->fail('Expected exception');
        } catch (InvalidArgumentException) {
        }
        $this->facts->attachSource($fact, $this->source());
        $this->facts->changeStatus($fact->fresh(), VerificationStatus::ExpertVerified, null, 'checked');
        $this->assertSame('expert_verified', $fact->fresh()->verification_status);
        $this->assertDatabaseHas('museum_verification_logs', ['to_status' => 'expert_verified']);
    }

    public function test_raw_documents_cannot_be_deleted(): void
    {
        $raw = RawDocument::create(['disk' => 'museum_raw', 'path' => 'x', 'sha256' => str_repeat('a', 64)]);
        $this->expectException(\LogicException::class);
        $raw->delete();
    }
}
