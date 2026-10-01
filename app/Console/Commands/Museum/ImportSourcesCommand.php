<?php

namespace App\Console\Commands\Museum;

use App\Museum\Importers\ImportRun;
use App\Museum\Importers\SourceRegistryImporter;
use Illuminate\Console\Command;

class ImportSourcesCommand extends Command
{
    protected $signature = 'museum:import:sources {path=database/data/source_registry.json}';

    protected $description = 'Import bibliographic sources into the Source Registry (unverified, crawl pending review)';

    public function handle(SourceRegistryImporter $importer): int
    {
        $batch = $importer->run(base_path($this->argument('path')))->batch->fresh();
        $this->table(['Metric', 'Count'], ImportRun::reportLines($batch));
        foreach ($batch->error_log ?? [] as $e) {
            $this->warn($e['message'].' '.json_encode($e['context'], JSON_UNESCAPED_UNICODE));
        }

        return $batch->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
