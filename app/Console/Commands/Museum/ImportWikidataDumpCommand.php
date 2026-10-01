<?php

namespace App\Console\Commands\Museum;

use App\Museum\Importers\ImportRun;
use App\Museum\Importers\WikidataDumpImporter;
use Illuminate\Console\Command;

class ImportWikidataDumpCommand extends Command
{
    protected $signature = 'museum:import:wikidata-dump {path : latest-all.json.gz, .json, or - for stdin} {--root=} {--limit=} {--publish}';

    protected $description = 'Village-level Hormozgan geography from the Wikidata JSON dump (official bulk download channel)';

    public function handle(WikidataDumpImporter $importer): int
    {
        ini_set('memory_limit', '2G');
        $batch = $importer->run($this->argument('path'), array_filter([
            'root' => $this->option('root'), 'limit' => $this->option('limit'), 'publish' => (bool) $this->option('publish'),
        ], fn ($v) => $v !== null && $v !== false), fn ($lines, $cands) => $this->line("  scanned {$lines} lines, {$cands} Iranian candidates"))->batch->fresh();
        $this->table(['Metric', 'Count'], ImportRun::reportLines($batch));

        return self::SUCCESS;
    }
}
