<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\DuplicateCandidate;
use App\Models\Museum\Entity;
use App\Models\Museum\EntityMerge;
use App\Models\Museum\Place;
use App\Museum\Services\DuplicateDetector;
use App\Museum\Services\EntityResolver;
use App\Museum\Services\EntityService;
use App\Museum\Services\FactService;
use App\Museum\Services\MergeService;
use App\Museum\Services\VerificationService;

class EntityResolutionTest extends MuseumTestCase
{
    public function test_all_spellings_resolve_to_same_entity(): void
    {
        $city = $this->entity('city', 'بندرعباس', ['name_fa' => 'بندرعباس', 'name_en' => 'Bandar Abbas']);
        $resolver = app(EntityResolver::class);

        foreach (['بندر عباس', 'بندرعباس', 'Bandar Abbas', 'Bandar-e Abbas', 'bandar-e ‘abbās'] as $spelling) {
            $r = $resolver->resolve($spelling, ['types' => ['place']]);
            $this->assertSame(EntityResolver::MATCHED, $r['decision'], $spelling);
            $this->assertSame($city->id, $r['entity']->id, $spelling);
        }
    }

    public function test_historical_name_resolves_with_lower_score(): void
    {
        $city = $this->entity('city', 'Fixture City');
        app(EntityService::class)->addHistoricalName($city, ['name' => 'Fixture Oldname', 'year_from' => 1600, 'source_id' => $this->source()->id]);

        $r = app(EntityResolver::class)->resolve('Fixture Oldname');
        $this->assertSame($city->id, $r['entity']->id);
        $this->assertSame(EntityResolver::REVIEW, $r['decision']); // historical names need human confirmation
    }

    public function test_homonymous_villages_are_ambiguous_unless_context_given(): void
    {
        $countyA = $this->entity('county', 'County A');
        $countyB = $this->entity('county', 'County B');
        $v1 = $this->entity('village', 'Fixture Kalat', [], ['parent_id' => $countyA->id]);
        $v2 = $this->entity('village', 'Fixture Kalat', [], ['parent_id' => $countyB->id]);
        $resolver = app(EntityResolver::class);

        $this->assertNotSame(EntityResolver::MATCHED, $resolver->resolve('Fixture Kalat')['decision']);
        $r = $resolver->resolve('Fixture Kalat', ['parent_place_id' => $countyB->id]);
        $this->assertSame($v2->id, $r['entity']->id);
        $this->assertSame(EntityResolver::MATCHED, $r['decision']);
    }

    public function test_unknown_surface_is_new(): void
    {
        $this->assertSame(EntityResolver::NEW, app(EntityResolver::class)->resolve('Nothing Like This')['decision']);
    }

    public function test_place_paths_are_materialized_and_rerooted(): void
    {
        $province = $this->entity('province', 'P');
        $county = $this->entity('county', 'C', [], ['parent_id' => $province->id]);
        $village = $this->entity('village', 'V', [], ['parent_id' => $county->id]);
        $this->assertSame("/{$province->id}/{$county->id}/{$village->id}/", Place::find($village->id)->path);

        $other = $this->entity('county', 'C2', [], ['parent_id' => $province->id]);
        app(EntityService::class)->setPlaceParent($county, $other);
        $this->assertSame("/{$province->id}/{$other->id}/{$county->id}/{$village->id}/", Place::find($village->id)->path);
    }

    public function test_merge_is_non_destructive(): void
    {
        $a = $this->entity('food', 'Fixture Dish');
        $b = $this->entity('food', 'Fixture Dish Variant');
        app(FactService::class)->assert($a, 'description', 'desc', ['source' => $this->source()]);

        app(MergeService::class)->merge($a, $b, null, 'same dish');

        $a = Entity::find($a->id);
        $this->assertSame($b->id, $a->merged_into_id);
        $this->assertSame(1, EntityMerge::count());
        $this->assertSame(1, $b->facts()->count());
        $r = app(EntityResolver::class)->resolve('Fixture Dish');
        $this->assertSame($b->id, $r['entity']->id);
    }

    public function test_duplicate_detection_queues_but_does_not_merge(): void
    {
        $a = $this->entity('village', 'Fixture Dup', ['latitude' => 27.1, 'longitude' => 56.2]);
        $b = $this->entity('village', 'Fixture  Dup', ['latitude' => 27.1001, 'longitude' => 56.2001]);
        $far = $this->entity('village', 'Fixture Dup', ['latitude' => 26.0, 'longitude' => 54.0]);

        $n = app(DuplicateDetector::class)->scan($a);
        $this->assertSame(1, $n);
        $dc = DuplicateCandidate::sole();
        $this->assertSame('pending', $dc->status);
        $this->assertNull(Entity::find($b->id)->merged_into_id);
    }

    public function test_publication_requires_sourced_verified_fact(): void
    {
        $e = $this->entity('village', 'Fixture Village');
        $svc = app(VerificationService::class);
        $this->assertFalse($svc->publish($e, null));

        app(FactService::class)->assert($e, 'description', 'desc', ['source' => $this->source(), 'status' => 'source_verified']);
        $this->assertTrue($svc->publish($e->fresh(), null));
        $this->assertTrue($e->fresh()->isPublished());
    }
}
