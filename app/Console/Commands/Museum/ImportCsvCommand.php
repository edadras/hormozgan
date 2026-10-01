<?php

namespace App\Console\Commands\Museum;

use App\Museum\Importers\ImportRun;
use App\Museum\Importers\TabularImporter;
use Illuminate\Console\Command;

class ImportCsvCommand extends Command
{
    protected $signature = 'museum:import:csv {kind : words|sentences|proverbs|historical_names|place_facts} {path}
        {--status=source_verified : Verification status for imported rows (unverified|source_verified|expert_verified)}';

    protected $description = 'Import a curated, sourced CSV dataset (see docs/museum/IMPORT_FORMATS.md)';

    public function handle(TabularImporter $importer): int
    {
        $batch = $importer->run($this->argument('kind'), $this->argument('path'), null, $this->option('status'))->batch->fresh();
        $this->table(['Metric', 'Count'], ImportRun::reportLines($batch));
        foreach (array_slice($batch->error_log ?? [], 0, 30) as $e) {
            $this->warn($e['message'].' '.json_encode($e['context']));
        }

        return self::SUCCESS;
    }
}
