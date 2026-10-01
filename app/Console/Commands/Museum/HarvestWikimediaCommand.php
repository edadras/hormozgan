<?php

namespace App\Console\Commands\Museum;

use App\Museum\Importers\ImportRun;
use App\Museum\Importers\WikimediaHarvester;
use Illuminate\Console\Command;

class HarvestWikimediaCommand extends Command
{
    protected $signature = 'museum:harvest:wikimedia {--root= : Category (default رده:استان_هرمزگان)} {--depth=5} {--limit=50000} {--publish}';

    protected $description = 'Harvest Hormozgan articles (all domains) from Persian Wikipedia categories + Wikidata + Commons images, robots-compliant';

    public function handle(WikimediaHarvester $harvester): int
    {
        ini_set('memory_limit', '2G');
        $batch = $harvester->run(array_filter([
            'root' => $this->option('root'), 'depth' => (int) $this->option('depth'), 'limit' => (int) $this->option('limit'),
            'publish' => (bool) $this->option('publish'),
        ], fn ($v) => $v !== null), function (string $phase, string $what, int $n) {
            if ($phase === 'category' || $n % 50 === 0) {
                $this->line("  [{$phase}] {$n} — {$what}");
            }
        })->batch->fresh();
        $this->table(['Metric', 'Count'], ImportRun::reportLines($batch));
        $this->line(json_encode($batch->report, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
