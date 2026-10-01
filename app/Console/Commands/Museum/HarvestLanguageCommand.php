<?php

namespace App\Console\Commands\Museum;

use App\Museum\Importers\ImportRun;
use App\Museum\Importers\LanguageHarvester;
use Illuminate\Console\Command;

class HarvestLanguageCommand extends Command
{
    protected $signature = 'museum:harvest:language';

    protected $description = 'Import dialects, glossaries, example sentences and grammar tables from the Wikipedia articles on Hormozgan languages';

    public function handle(LanguageHarvester $h): int
    {
        $batch = $h->run()->batch->fresh();
        $this->table(['Metric', 'Count'], ImportRun::reportLines($batch));
        $this->line(json_encode($batch->report, JSON_UNESCAPED_UNICODE));
        foreach (array_slice($batch->error_log ?? [], 0, 20) as $e) {
            $this->warn($e['message'].' '.json_encode($e['context'], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
