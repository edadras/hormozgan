<?php

namespace App\Museum\Importers;

use App\Models\Museum\Entity;
use App\Museum\Pipeline\DocumentFetcher;

/**
 * Village-level geography from the Wikidata JSON dump (dumps.wikimedia.org, CC0) — the
 * sanctioned bulk channel. Reverse lookups ("what is located in this rural district?") are
 * not possible through robots-allowed live endpoints, but are trivial over the dump.
 *
 *   pass 1  stream the dump; keep Iranian items (P17=Q794) that have P131, trimmed to the
 *           labels/claims we use, in a temporary JSONL file
 *   pass 2  membership closure: an item belongs to Hormozgan if its P131 points to the root
 *           or to an item already known to belong (iterate until stable)
 *   pass 3  preserve the trimmed extract as a raw document, then import through the same
 *           code path as the live importer (identical provenance and idempotency)
 *
 * Input: path to latest-all.json.gz / .json, or "-" for stdin (e.g. `lbzcat dump.bz2 | ...`).
 */
class WikidataDumpImporter
{
    private const KEEP_CLAIMS = ['P31', 'P131', 'P150', 'P17', 'P625', 'P1082', 'P2044', 'P2046', 'P571', 'P1435', 'P1369', 'P373', 'P36', 'P706'];

    private const IRAN = 'Q794';

    public function __construct(private WikidataGeographyImporter $importer, private DocumentFetcher $fetcher) {}

    public function run(string $path, array $options = [], ?callable $progress = null): ImportRun
    {
        $root = $options['root'] ?? config('museum.wikidata.hormozgan_qid');
        $source = $this->importer->registerSource();
        $run = ImportRun::start('wikidata_dump', ['path' => $path] + $options, $source->id, $options['user_id'] ?? null, $path);

        $stream = $path === '-' ? fopen('php://stdin', 'r') : fopen(str_ends_with($path, '.gz') ? 'compress.zlib://'.$path : $path, 'r');
        if (! $stream) {
            $run->error('cannot open dump '.$path);

            return tap($run, fn ($r) => $r->finish('failed'));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wd-cand-');
        $out = fopen($tmp, 'w');
        $parents = [];
        $offsets = [];
        $lines = 0;
        while (($line = fgets($stream)) !== false) {
            $lines++;
            if ($progress && $lines % 1000000 === 0) {
                $progress($lines, count($parents));
            }
            // Cheap prefilter before JSON decoding (the dump has ~100M lines).
            if (! str_contains($line, '"P131"') || ! str_contains($line, '"'.self::IRAN.'"')) {
                continue;
            }
            $json = json_decode(rtrim(trim($line), ','), true);
            if (! is_array($json) || ($json['type'] ?? '') !== 'item') {
                continue;
            }
            $country = $this->values($json, 'P17');
            if (! in_array(self::IRAN, $country, true)) {
                continue;
            }
            $trimmed = $this->trim($json);
            $offsets[$json['id']] = ftell($out);
            fwrite($out, json_encode($trimmed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $parents[$json['id']] = $this->values($json, 'P131');
        }
        fclose($out);
        if ($path !== '-') {
            fclose($stream);
        }

        // Membership closure.
        $members = array_fill_keys(Entity::whereNotNull('wikidata_id')->pluck('wikidata_id')->all(), true);
        $members[$root] = true;
        $selected = [];
        do {
            $added = 0;
            foreach ($parents as $qid => $p131) {
                if (! isset($selected[$qid]) && array_intersect_key(array_flip($p131), $members)) {
                    $selected[$qid] = true;
                    $members[$qid] = true;
                    $added++;
                }
            }
        } while ($added > 0);
        $limit = (int) ($options['limit'] ?? PHP_INT_MAX);

        // Preserve the exact extract that is imported.
        $in = fopen($tmp, 'r');
        $items = [];
        $extract = '';
        foreach (array_keys($selected) as $qid) {
            if (count($items) >= $limit) {
                break;
            }
            fseek($in, $offsets[$qid]);
            $row = fgets($in);
            $extract .= $row;
            $items[$qid] = json_decode($row, true);
        }
        fclose($in);
        @unlink($tmp);
        $run->inc('documents_processed', count($items));
        if ($items) {
            $raw = $this->fetcher->store($extract, [
                'url' => $options['dump_url'] ?? 'https://dumps.wikimedia.org/wikidatawiki/entities/latest-all.json.gz',
                'content_type' => 'application/x-ndjson',
                'source_id' => $source->id,
                'license' => 'cc0',
                'metadata' => ['dump_path' => $path, 'lines_scanned' => $lines, 'iran_candidates' => count($parents), 'root' => $root],
            ]);
            foreach ($items as $qid => $item) {
                $items[$qid]['_raw_document_id'] = $raw->id;
            }
            $this->importer->importCollected($items, $run, $options);
        }

        return tap($run, fn ($r) => $r->finish('completed', ['lines_scanned' => $lines, 'iran_candidates' => count($parents), 'selected' => count($items)]));
    }

    private function trim(array $e): array
    {
        $langs = ['fa', 'en', 'ar'];

        return [
            'type' => 'item',
            'id' => $e['id'],
            'lastrevid' => $e['lastrevid'] ?? null,
            'labels' => array_intersect_key($e['labels'] ?? [], array_flip($langs)),
            'aliases' => array_intersect_key($e['aliases'] ?? [], array_flip($langs)),
            'claims' => array_intersect_key($e['claims'] ?? [], array_flip(self::KEEP_CLAIMS)),
        ];
    }

    private function values(array $e, string $pid): array
    {
        $out = [];
        foreach ($e['claims'][$pid] ?? [] as $c) {
            if (($c['rank'] ?? '') !== 'deprecated' && ($id = $c['mainsnak']['datavalue']['value']['id'] ?? null)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
