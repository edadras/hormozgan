<?php

namespace App\Museum\Importers;

use App\Models\Museum\CrawlerSource;
use App\Models\Museum\Entity;
use App\Models\Museum\Source;
use App\Museum\Pipeline\DocumentFetcher;
use App\Museum\Services\DuplicateDetector;
use App\Museum\Services\EntityResolver;
use App\Museum\Services\EntityService;
use App\Museum\Services\FactService;
use App\Museum\Services\SourceService;
use App\Museum\Services\VerificationService;

/**
 * Phase A geography from Wikidata (CC0).
 *
 * Access path: https://www.wikidata.org/wiki/Special:EntityData/{QID}.json — the Linked Data
 * interface, which robots.txt allows (the SPARQL endpoint and /w/api.php are disallowed for
 * generic agents, so they are not used). The admin hierarchy is walked breadth-first through
 * P150 ("contains the administrative territorial entity"), P36 (capital) and P706 (located on terrain
 * feature, e.g. islands), starting at Hormozgan (Q633659). Reverse lookups (what is located in X?)
 * would need SPARQL/WhatLinksHere, which robots.txt disallows; for village-level coverage use the
 * dump importer (museum:import:wikidata-dump), the sanctioned bulk channel.
 *
 * Provenance: every fact cites the Wikidata source with locator "{QID}#{PID}@rev{lastrevid}"
 * and an extract holding the verbatim claim JSON (including Wikidata's own references).
 * Facts are `source_verified` (machine-read from the cited record); the source's reliability
 * tier (3) is kept separately. Missing values are simply not asserted.
 */
class WikidataGeographyImporter
{
    /** P31 class → [entity type, place_type], in priority order. */
    public const CLASS_MAP = [
        'Q1344695' => ['province', 'province'],      // province of Iran
        'Q1615742' => ['province', 'province'],
        'Q137535' => ['county', 'county'],           // county of Iran
        'Q11353' => ['district', 'district'],        // district of Iran
        'Q56557504' => ['city', 'city'],             // city of Iran
        'Q15125752' => ['rural_district', 'rural_district'],
        'Q23442' => ['island', 'island'],
        'Q44782' => ['port', 'port'],
        'Q532' => ['village', 'village'],
        'Q5084' => ['village', 'hamlet'],
        'Q486972' => ['village', 'settlement'],      // human settlement (abadi)
        'Q8502' => ['mountain', 'mountain'],
        'Q4022' => ['river', 'river'],
        'Q47521' => ['natural_feature', 'stream'],
        'Q47053' => ['bay', 'estuary'],
        'Q2065736' => ['historical_site', 'cultural_property'],
        'Q5958900' => ['historical_site', 'national_heritage'],
        'Q23413' => ['historical_site', 'castle'],
        'Q303874' => ['building', 'ab_anbar'],
        'Q32815' => ['building', 'mosque'],
        'Q162875' => ['building', 'mausoleum'],
        'Q459297' => ['historical_site', 'qanat'],
    ];

    private ?CrawlerSource $crawler = null;

    private ?Source $source = null;

    private array $classLabels = [];

    public function __construct(
        private DocumentFetcher $fetcher,
        private EntityService $entities,
        private EntityResolver $resolver,
        private FactService $facts,
        private SourceService $sources,
        private VerificationService $verification,
        private DuplicateDetector $duplicates,
    ) {}

    public function run(array $options = [], ?callable $progress = null): ImportRun
    {
        $root = $options['root'] ?? config('museum.wikidata.hormozgan_qid');
        $limit = (int) ($options['limit'] ?? 20000);
        $maxDepth = (int) ($options['depth'] ?? 6);
        $publish = (bool) ($options['publish'] ?? false);
        $this->source = $this->registerSource();
        $this->crawler = $this->registerCrawler();
        $run = ImportRun::start('wikidata_geography', $options, $this->source->id, $options['user_id'] ?? null, 'Special:EntityData BFS from '.$root);
        $run->inc('sources_found');
        $run->inc('sources_accepted');

        // ---- pass 1: BFS fetch (raw preserved)
        $items = [];
        $queue = [[$root, 0, 'root']];
        $seen = [$root => true];
        $deferred = [];
        while ($queue && count($items) < $limit) {
            [$qid, $depth, $via] = array_shift($queue);
            $entity = $this->fetchEntity($qid, $run);
            if (! $entity) {
                continue;
            }
            // Items reached through P36/P706 must themselves be located inside the collected set;
            // decided after the walk, when the whole hierarchy is known.
            if ($via !== 'root' && $via !== 'P150') {
                $deferred[$entity['id']] = $entity;

                continue;
            }
            $items[$entity['id']] = $entity;
            $run->inc('documents_processed');
            $progress && $progress('fetched', $entity['id'], count($items));
            if ($depth >= $maxDepth) {
                continue;
            }
            // Downward/forward links only: contained units (P150), capital (P36), terrain feature such as islands (P706).
            foreach (['P150', 'P36', 'P706'] as $pid) {
                foreach ($this->itemValues($entity, $pid) as $child) {
                    if (! isset($seen[$child])) {
                        $seen[$child] = true;
                        $queue[] = [$child, $depth + 1, $pid];
                    }
                }
            }
        }

        do {
            $added = 0;
            foreach ($deferred as $qid => $entity) {
                if (array_intersect($this->itemValues($entity, 'P131'), array_keys($items))) {
                    $items[$qid] = $entity;
                    unset($deferred[$qid]);
                    $run->inc('documents_processed');
                    $added++;
                }
            }
        } while ($added > 0);

        $this->importCollected($items, $run, $options);

        return tap($run, fn ($r) => $r->finish('completed', ['items' => count($items), 'root' => $root]));
    }

    /**
     * Imports already-collected EntityData items (from the live walk or a dump extract).
     * Each item may carry '_raw_document_id' pointing at its preserved raw copy.
     */
    public function importCollected(array $items, ImportRun $run, array $options = []): void
    {
        $this->source ??= $this->registerSource();
        $this->crawler ??= $this->registerCrawler();
        $publish = (bool) ($options['publish'] ?? false);
        $this->loadClassLabels($items, $run);

        // ---- pass 2: entities
        $this->facts->deferCounters = true;
        $map = [];
        foreach ($items as $qid => $data) {
            try {
                $map[$qid] = $this->upsertEntity($data, $run);
            } catch (\Throwable $e) {
                $run->error($e->getMessage(), ['qid' => $qid]);
            }
        }

        // ---- pass 3: facts + hierarchy
        foreach ($items as $qid => $data) {
            if (! isset($map[$qid])) {
                continue;
            }
            try {
                $this->importFacts($map[$qid], $data, $map, $run);
            } catch (\Throwable $e) {
                $run->error($e->getMessage(), ['qid' => $qid]);
            }
        }
        $this->facts->flushCounters();
        $this->facts->deferCounters = false;

        // ---- pass 4: duplicates, status, publication
        foreach ($map as $entity) {
            $run->inc('duplicates', $this->duplicates->scan($entity));
            $this->verification->refreshEntityStatus($entity->fresh());
            if ($publish && ! $entity->fresh()->isPublished()) {
                $this->verification->publish($entity->fresh(), $options['user_id'] ?? null);
            }
        }
        $this->source->forceFill(['retrieved_at' => now()])->save();
    }

    public function registerSource(): Source
    {
        return $this->sources->register([
            'source_type' => 'open_dataset',
            'title' => 'Wikidata: administrative and geographic items of Hormozgan Province (Q633659)',
            'publisher' => 'Wikimedia Foundation / Wikidata contributors',
            'url' => 'https://www.wikidata.org/wiki/Q633659',
            'language' => 'mul',
            'license' => 'cc0',
            'copyright_status' => 'public_domain_dedication',
            'reliability_tier' => 3,
            'crawl_policy' => 'allowed',
            'phase' => 'A',
            'priority' => 5,
            'topics' => ['geography', 'administrative divisions', 'population'],
            'regions' => ['Hormozgan'],
            'metadata' => ['short_label' => 'ویکی‌داده (Wikidata)'],
            'notes' => 'Community-maintained knowledge base. Each fact keeps the item revision and the claim JSON, '
                .'including Wikidata\'s own references (e.g. census sources), for re-verification.',
        ]);
    }

    private function registerCrawler(): CrawlerSource
    {
        return CrawlerSource::firstOrCreate(['kind' => 'wikidata_entitydata', 'source_id' => $this->source->id], [
            'name' => 'Wikidata Linked Data interface (Special:EntityData)',
            'base_url' => 'https://www.wikidata.org/wiki/Special:EntityData/',
            'allowed_patterns' => ['https://www.wikidata.org/wiki/Special:EntityData/*'],
            'terms_url' => 'https://foundation.wikimedia.org/wiki/Policy:User-Agent_policy',
            'terms_reviewed' => true,
            'crawl_delay_ms' => 1000,
            'max_documents' => 20000,
            'enabled' => true,
            'priority' => 5,
            'config' => ['note' => 'Structured data is CC0. /sparql and /w/ are disallowed by robots.txt and are not used.'],
        ]);
    }

    private function fetchEntity(string $qid, ImportRun $run): ?array
    {
        if (! preg_match('/^Q\d+$/', $qid)) {
            return null;
        }
        $url = 'https://www.wikidata.org/wiki/Special:EntityData/'.$qid.'.json';
        $res = $this->fetcher->fetch($this->crawler, $url);
        if (! in_array($res['status'], ['fetched', 'unchanged'], true) || empty($res['raw'])) {
            $run->error('fetch '.$res['status'].': '.($res['reason'] ?? ''), ['qid' => $qid]);

            return null;
        }
        $json = json_decode($res['raw']->contents(), true);
        $entity = is_array($json['entities'] ?? null) ? reset($json['entities']) : null;
        if (! $entity || ($entity['type'] ?? null) !== 'item') {
            $run->error('unexpected EntityData payload', ['qid' => $qid]);

            return null;
        }
        $entity['_raw_document_id'] = $res['raw']->id;

        return $entity;
    }

    private function loadClassLabels(array $items, ImportRun $run): void
    {
        $refs = [];
        foreach ($items as $data) {
            foreach (['P31', 'P1435'] as $pid) {
                foreach ($this->itemValues($data, $pid) as $q) {
                    $refs[$q] = true;
                }
            }
        }
        foreach (array_keys($refs) as $q) {
            if (isset($items[$q])) {
                $this->classLabels[$q] = $this->labels($items[$q]);

                continue;
            }
            $e = $this->fetchEntity($q, $run);
            $this->classLabels[$q] = $e ? $this->labels($e) : ['en' => null, 'fa' => null];
        }
    }

    private function upsertEntity(array $data, ImportRun $run): Entity
    {
        $qid = $data['id'];
        $labels = $this->labels($data);
        [$typeKey, $placeType] = $this->classify($data);
        $coords = $this->firstValue($data, 'P625');
        $attrs = array_filter([
            'canonical_name' => $labels['fa'] ?? $labels['en'] ?? $qid,
            'name_fa' => $labels['fa'],
            'name_en' => $labels['en'],
            'name_ar' => $labels['ar'],
            'latitude' => $coords['latitude'] ?? null,
            'longitude' => $coords['longitude'] ?? null,
        ], fn ($v) => $v !== null);

        $existing = Entity::where('wikidata_id', $qid)->first();
        if ($existing) {
            $this->entities->update($existing, $attrs);
            $entity = $existing;
            $run->inc('entities_matched');
        } else {
            $entity = $this->entities->create($typeKey, $attrs + [
                'wikidata_id' => $qid,
                'external_ids' => ['wikidata' => $qid, 'p31' => $this->itemValues($data, 'P31')],
                'verification_status' => 'unverified',
            ], ['place_type' => $placeType, 'is_historical' => false]);
            $run->inc('entities_created');
        }
        foreach (['fa', 'en', 'ar'] as $lang) {
            foreach ($data['aliases'][$lang] ?? [] as $alias) {
                $this->entities->addAlias($entity, $alias['value'], ['type' => 'spelling_variant', 'language' => $lang, 'source_id' => $this->source->id]);
            }
        }

        return $entity;
    }

    private function importFacts(Entity $entity, array $data, array $map, ImportRun $run): void
    {
        $qid = $data['id'];
        $rev = $data['lastrevid'] ?? null;
        $base = ['source' => $this->source, 'status' => 'source_verified', 'method' => 'structured_import',
            'confidence' => 1.0, 'import_batch_id' => $run->batch->id];

        $assert = function (string $prop, mixed $value, array $claim, array $extra = []) use ($entity, $qid, $rev, $base, $data, $run) {
            $locator = $qid.'#'.$claim['mainsnak']['property'].($rev ? '@rev'.$rev : '');
            $extract = $this->sources->extract($this->source, json_encode($claim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'locator' => $locator, 'raw_document_id' => $data['_raw_document_id'] ?? null, 'extracted_by' => 'import',
            ]);
            $fact = $this->facts->assert($entity, $prop, $value, $base + $extra + ['extract' => $extract, 'locator' => $locator]);
            $run->inc('facts_extracted');
            if ($fact->conflict_id) {
                $run->inc('conflicts');
            }
        };

        foreach ($this->claims($data, 'P625') as $c) {
            $v = $c['mainsnak']['datavalue']['value'];
            $assert('coordinates', ['lat' => $v['latitude'], 'lng' => $v['longitude']], $c);
        }
        foreach ($this->claims($data, 'P1082') as $c) {
            $amount = $c['mainsnak']['datavalue']['value']['amount'] ?? null;
            $year = $this->qualifierYear($c, 'P585');
            if ($amount !== null) {
                $assert('population', ltrim($amount, '+'), $c, ['qualifiers' => $year ? ['census_year' => $year] : null]);
            }
        }
        foreach ($this->claims($data, 'P2044') as $c) {
            $amount = $c['mainsnak']['datavalue']['value']['amount'] ?? null;
            $unit = $c['mainsnak']['datavalue']['value']['unit'] ?? '';
            if ($amount !== null && str_ends_with($unit, '/Q11573')) { // metre
                $assert('elevation', ltrim($amount, '+'), $c);
            }
        }
        foreach ($this->claims($data, 'P2046') as $c) {
            $amount = $c['mainsnak']['datavalue']['value']['amount'] ?? null;
            $unit = $c['mainsnak']['datavalue']['value']['unit'] ?? '';
            if ($amount !== null && str_ends_with($unit, '/Q712226')) { // square kilometre
                $assert('area', ltrim($amount, '+'), $c);
            }
        }
        foreach ($this->claims($data, 'P571') as $c) {
            $t = $c['mainsnak']['datavalue']['value'] ?? null;
            if ($t && ($t['precision'] ?? 0) >= 9 && preg_match('/^([+-]\d+)-/', $t['time'], $m)) {
                $assert('founding_year', (int) $m[1], $c);
            }
        }
        foreach ($this->claims($data, 'P1435') as $c) {
            $q = $c['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($q) {
                $l = $this->classLabels[$q] ?? [];
                $assert('heritage_designation', trim(($l['en'] ?? $l['fa'] ?? $q).' ('.$q.')'), $c,
                    ['localized' => array_filter(['fa' => $l['fa'] ?? null, 'en' => $l['en'] ?? null])]);
            }
        }
        foreach ($this->claims($data, 'P1369') as $c) {
            $v = $c['mainsnak']['datavalue']['value'] ?? null;
            if (is_string($v) && $v !== '') {
                $assert('heritage_registration_number', $v, $c);
            }
        }
        foreach ($this->claims($data, 'P373') as $c) {
            $v = $c['mainsnak']['datavalue']['value'] ?? null;
            if (is_string($v) && $v !== '') {
                $assert('commonscat', $v, $c);
            }
        }
        foreach ($this->claims($data, 'P31') as $c) {
            $q = $c['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($q) {
                $l = $this->classLabels[$q] ?? [];
                $assert('instance_of_label', trim(($l['en'] ?? $q).' ('.$q.')'), $c,
                    ['localized' => array_filter(['fa' => $l['fa'] ?? null, 'en' => $l['en'] ?? null])]);
            }
        }

        // Administrative parent(s). Current parent (no end time) sets the place hierarchy.
        $currentParent = null;
        foreach ($this->claims($data, 'P131') as $c) {
            $q = $c['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($q && ! isset($map[$q])) {
                // Parent imported in an earlier run (e.g. live walk before a dump import).
                $known = Entity::where('wikidata_id', $q)->first();
                if ($known) {
                    $map[$q] = $known;
                }
            }
            if (! $q || ! isset($map[$q])) {
                continue;
            }
            $endYear = $this->qualifierYear($c, 'P582');
            $startYear = $this->qualifierYear($c, 'P580');
            $assert('located_in', $map[$q], $c, ['qualifiers' => array_filter(['valid_from' => $startYear, 'valid_to' => $endYear]) ?: null]);
            if (! $endYear && ($currentParent === null || ($c['rank'] ?? '') === 'preferred')) {
                $currentParent = $map[$q];
            }
        }
        if ($currentParent && $entity->place) {
            try {
                $this->entities->setPlaceParent($entity, $currentParent);
            } catch (\InvalidArgumentException $e) {
                $run->error($e->getMessage(), ['qid' => $qid]);
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    private function classify(array $data): array
    {
        $classes = $this->itemValues($data, 'P31');
        foreach (self::CLASS_MAP as $q => $mapped) {
            if (in_array($q, $classes, true)) {
                return $mapped;
            }
        }

        return ['place', 'other'];
    }

    private function labels(array $data): array
    {
        return [
            'fa' => $data['labels']['fa']['value'] ?? null,
            'en' => $data['labels']['en']['value'] ?? null,
            'ar' => $data['labels']['ar']['value'] ?? null,
        ];
    }

    /** Claims for a property, excluding deprecated-rank statements and novalue/somevalue snaks. */
    private function claims(array $data, string $pid): array
    {
        return array_values(array_filter($data['claims'][$pid] ?? [], fn ($c) => ($c['rank'] ?? '') !== 'deprecated'
            && ($c['mainsnak']['snaktype'] ?? '') === 'value'));
    }

    private function itemValues(array $data, string $pid): array
    {
        $out = [];
        foreach ($this->claims($data, $pid) as $c) {
            if ($id = $c['mainsnak']['datavalue']['value']['id'] ?? null) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    private function firstValue(array $data, string $pid): ?array
    {
        $c = $this->claims($data, $pid)[0] ?? null;

        return $c['mainsnak']['datavalue']['value'] ?? null;
    }

    private function qualifierYear(array $claim, string $pid): ?int
    {
        $t = $claim['qualifiers'][$pid][0]['datavalue']['value'] ?? null;
        if ($t && ($t['precision'] ?? 0) >= 9 && preg_match('/^([+-]\d+)-/', $t['time'] ?? '', $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
