<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\Entity;
use App\Models\Museum\Fact;
use App\Models\Museum\Place;
use App\Museum\Importers\WikidataGeographyImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Uses small synthetic EntityData payloads (test fixtures with fake QIDs Q9000xx), not real data.
 */
class WikidataImporterTest extends MuseumTestCase
{
    private function item(string $qid, string $fa, string $en, string $class, array $extraClaims = []): array
    {
        $snak = fn ($pid, $type, $value) => ['mainsnak' => ['snaktype' => 'value', 'property' => $pid, 'datavalue' => ['type' => $type, 'value' => $value]], 'rank' => 'normal'];
        $claims = ['P31' => [$snak('P31', 'wikibase-entityid', ['id' => $class])]];
        foreach ($extraClaims as $pid => $list) {
            foreach ($list as [$type, $value, $qualifiers]) {
                $c = $snak($pid, $type, $value);
                if ($qualifiers) {
                    $c['qualifiers'] = $qualifiers;
                }
                $claims[$pid][] = $c;
            }
        }

        return ['entities' => [$qid => ['type' => 'item', 'id' => $qid, 'lastrevid' => 123,
            'labels' => ['fa' => ['value' => $fa], 'en' => ['value' => $en]],
            'aliases' => ['en' => [['value' => $en.' alt']]],
            'claims' => $claims]]];
    }

    public function test_import_walks_hierarchy_with_provenance(): void
    {
        Storage::fake('museum_raw');
        config(['museum.crawler.min_delay_ms' => 0]);
        $time = fn ($y) => ['time' => "+{$y}-01-01T00:00:00Z", 'precision' => 9];
        Http::fake([
            'www.wikidata.org/robots.txt' => Http::response("User-agent: *\nDisallow: /w/"),
            '*/Special:EntityData/Q900001.json' => Http::response($this->item('Q900001', 'استان آزمایشی', 'Fixture Province', 'Q1344695', [
                'P150' => [['wikibase-entityid', ['id' => 'Q900002'], null]],
                'P1082' => [['quantity', ['amount' => '+1000'], ['P585' => [['datavalue' => ['value' => $time(2016)]]]]],
                    ['quantity', ['amount' => '+900'], ['P585' => [['datavalue' => ['value' => $time(2011)]]]]]],
            ])),
            '*/Special:EntityData/Q900002.json' => Http::response($this->item('Q900002', 'شهرستان آزمایشی', 'Fixture County', 'Q137535', [
                'P131' => [['wikibase-entityid', ['id' => 'Q900001'], null]],
                'P625' => [['globecoordinate', ['latitude' => 27.1, 'longitude' => 56.2], null]],
            ])),
            '*/Special:EntityData/Q1344695.json' => Http::response($this->item('Q1344695', 'استان ایران', 'province of Iran', 'Q1')),
            '*/Special:EntityData/Q137535.json' => Http::response($this->item('Q137535', 'شهرستان ایران', 'county of Iran', 'Q1')),
        ]);

        $run = app(WikidataGeographyImporter::class)->run(['root' => 'Q900001', 'publish' => true]);
        $b = $run->batch->fresh();
        $this->assertSame('completed', $b->status);
        $this->assertSame(2, $b->entities_created);
        $this->assertSame(0, $b->errors);

        $province = Entity::where('wikidata_id', 'Q900001')->sole();
        $county = Entity::where('wikidata_id', 'Q900002')->sole();
        $this->assertSame('province', $province->type->key);
        $this->assertSame("/{$province->id}/{$county->id}/", Place::find($county->id)->path);
        $this->assertSame(2, Fact::where('entity_id', $province->id)->whereHas('property', fn ($q) => $q->where('key', 'population'))->count());
        $fs = Fact::where('entity_id', $county->id)->whereHas('property', fn ($q) => $q->where('key', 'located_in'))->sole()->sources()->sole();
        $this->assertSame('Q900002#P131@rev123', $fs->locator);
        $this->assertStringContainsString('"P131"', $fs->extract->original_text);
        $this->assertTrue($county->fresh()->isPublished());
        // Only EntityData URLs were requested (robots-compliant).
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sparql') || str_contains($r->url(), '/w/api.php'));

        // Re-running is idempotent: matched, not duplicated.
        $again = app(WikidataGeographyImporter::class)->run(['root' => 'Q900001'])->batch->fresh();
        $this->assertSame(2, $again->entities_matched);
        $this->assertSame(2, Entity::whereNotNull('wikidata_id')->count());
    }
}
