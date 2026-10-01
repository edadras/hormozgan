<?php

namespace App\Console\Commands\Museum;

use App\Museum\Importers\ImportRun;
use App\Museum\Importers\WikidataGeographyImporter;
use Illuminate\Console\Command;

class ImportWikidataCommand extends Command
{
    protected $signature = 'museum:import:wikidata
        {--root= : Root item (default: Hormozgan Q633659)}
        {--limit=20000 : Maximum items to fetch}
        {--depth=6 : Maximum hierarchy depth}
        {--publish : Publish entities that satisfy the publication rule}';

    protected $description = 'Phase A: import Hormozgan administrative geography from Wikidata (robots-compliant EntityData access)';

    public function handle(WikidataGeographyImporter $importer): int
    {
        $bar = null;
        $batch = $importer->run([
            'root' => $this->option('root') ?: null,
            'limit' => (int) $this->option('limit'),
            'depth' => (int) $this->option('depth'),
            'publish' => (bool) $this->option('publish'),
        ], function (string $phase, string $qid, int $n) {
            if ($phase === 'fetched' && $n % 25 === 0) {
                $this->line("  fetched {$n} items (last {$qid})");
            }
        })->batch->fresh();
        $this->table(['Metric', 'Count'], ImportRun::reportLines($batch));
        $this->info('Import batch '.$batch->uuid.' '.$batch->status);

        return self::SUCCESS;
    }
}
