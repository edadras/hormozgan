<?php

namespace App\Console\Commands\Museum;

use App\Museum\Importers\CommonsGalleryHarvester;
use App\Museum\Importers\ImportRun;
use Illuminate\Console\Command;

class HarvestCommonsCommand extends Command
{
    protected $signature = 'museum:harvest:commons {--per-entity=6}';

    protected $description = 'Link licensed images from each published entity\'s Wikimedia Commons category (with per-file licence check)';

    public function handle(CommonsGalleryHarvester $h): int
    {
        $batch = $h->run(['per_entity' => (int) $this->option('per-entity')], function ($i, $n, $name) {
            if ($i % 20 === 0) {
                $this->line("  {$i}/{$n} {$name}");
            }
        })->batch->fresh();
        $this->table(['Metric', 'Count'], ImportRun::reportLines($batch));

        return self::SUCCESS;
    }
}
