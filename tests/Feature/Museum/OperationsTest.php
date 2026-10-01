<?php

namespace Tests\Feature\Museum;

use App\Models\Museum\Entity;
use App\Models\Museum\Fact;
use App\Models\Museum\HistoricalName;
use App\Models\Museum\Place;
use App\Models\Museum\RawDocument;
use App\Models\Museum\WordMeaning;
use App\Museum\Importers\TabularImporter;
use App\Museum\Importers\WikidataDumpImporter;
use App\Museum\Services\FactService;
use App\Museum\Services\VerificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Synthetic fixtures only (fake QIDs Q9100xx, "Fixture" names). */
class OperationsTest extends MuseumTestCase
{
    private function line(string $qid, string $label, string $class, array $p131, array $extra = []): string
    {
        $snak = fn ($pid, $id) => ['mainsnak' => ['snaktype' => 'value', 'property' => $pid, 'datavalue' => ['type' => 'wikibase-entityid', 'value' => ['id' => $id]]], 'rank' => 'normal'];
        $claims = ['P31' => [$snak('P31', $class)], 'P17' => [$snak('P17', 'Q794')]];
        foreach ($p131 as $p) {
            $claims['P131'][] = $snak('P131', $p);
        }

        return json_encode(['type' => 'item', 'id' => $qid, 'lastrevid' => 7, 'labels' => ['en' => ['value' => $label]],
            'claims' => $claims + $extra, 'sitelinks' => ['big' => str_repeat('x', 50)]]).",\n";
    }

    public function test_dump_import_resolves_membership_by_p131_closure(): void
    {
        Storage::fake('museum_raw');
        Http::fake(['*' => Http::response(['entities' => []])]);
        $dump = tempnam(sys_get_temp_dir(), 'dump').'.json.gz';
        $body = "[\n"
            .$this->line('Q910001', 'Fixture Rural District', 'Q15125752', ['Q633659'])
            .$this->line('Q910002', 'Fixture Village A', 'Q532', ['Q910001'])
            .$this->line('Q910003', 'Fixture Village B', 'Q532', ['Q910002'])     // nested: still inside
            .$this->line('Q910004', 'Elsewhere Village', 'Q532', ['Q999999'])     // other province
            .json_encode(['type' => 'item', 'id' => 'Q910005', 'labels' => [], 'claims' => []]).",\n"
            ."]\n";
        file_put_contents($dump, gzencode($body));

        $batch = app(WikidataDumpImporter::class)->run($dump)->batch->fresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(3, $batch->entities_created);
        $this->assertNull(Entity::where('wikidata_id', 'Q910004')->first());
        $village = Entity::where('wikidata_id', 'Q910003')->sole();
        $this->assertSame('village', $village->type->key);
        $rd = Entity::where('wikidata_id', 'Q910001')->sole();
        $this->assertStringContainsString('/'.$rd->id.'/', Place::find($village->id)->path);
        // Raw extract preserved, trimmed (no sitelinks).
        $raw = RawDocument::where('content_type', 'application/x-ndjson')->sole();
        $this->assertStringNotContainsString('sitelinks', $raw->contents());
        $this->assertSame($raw->id, Fact::first()->sources()->first()->extract->raw_document_id);
    }

    public function test_csv_imports_require_sources(): void
    {
        $county = $this->entity('county', 'Fixture County');
        $source = $this->source();
        $csv = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($csv, "word,meaning_fa,place,source_uuid,page,quote\n"
            ."fixtureword,معنی آزمایشی,Fixture County,{$source->uuid},12,fixtureword means test\n"
            ."nosource,معنی,,,\n");
        $batch = app(TabularImporter::class)->run('words', $csv)->batch->fresh();
        $this->assertSame(1, $batch->entities_created);
        $this->assertSame(1, $batch->errors);
        $this->assertSame('معنی آزمایشی', WordMeaning::sole()->meaning_fa);

        file_put_contents($csv, "place,historical_name,period,year_from,source_uuid,page\nFixture County,Fixture Oldname,Qajar,1850,{$source->uuid},4\n");
        app(TabularImporter::class)->run('historical_names', $csv);
        $this->assertSame('Fixture Oldname', HistoricalName::sole()->name);

        file_put_contents($csv, "place,property,value,census_year,source_uuid,page\nFixture County,population,,1976,{$source->uuid},9\n");
        app(TabularImporter::class)->run('place_facts', $csv);
        $this->assertTrue(Fact::where('entity_id', $county->id)->sole()->is_unknown);
    }

    public function test_backup_and_export_commands(): void
    {
        Storage::fake('museum_backup');
        $e = $this->entity('city', 'Fixture City');
        app(FactService::class)->assert($e, 'population', 10, ['source' => $this->source(), 'status' => 'source_verified']);
        app(VerificationService::class)->publish($e->fresh(), null);

        $this->artisan('museum:backup')->assertSuccessful();
        $runs = Storage::disk('museum_backup')->directories('runs');
        $this->assertCount(1, $runs);
        $manifest = json_decode(gzdecode(Storage::disk('museum_backup')->get($runs[0].'/manifest.json.gz')), true);
        $this->assertSame(1, $manifest['tables']['museum_facts']);

        $out = tempnam(sys_get_temp_dir(), 'exp').'.jsonl';
        $this->artisan('museum:export', ['path' => $out])->assertSuccessful();
        $row = json_decode(trim(file_get_contents($out)), true);
        $this->assertSame('population', $row['facts'][0]['property']);
        $this->assertNotEmpty($row['facts'][0]['sources'][0]['source']);
    }
}
