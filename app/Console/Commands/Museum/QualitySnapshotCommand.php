<?php

namespace App\Console\Commands\Museum;

use App\Museum\Services\QualityMetrics;
use Illuminate\Console\Command;

class QualitySnapshotCommand extends Command
{
    protected $signature = 'museum:quality {--json}';

    protected $description = 'Record and print the data-quality / big-data metrics snapshot';

    public function handle(QualityMetrics $m): int
    {
        $snap = $m->snapshot();
        if ($this->option('json')) {
            $this->line(json_encode($snap->metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->table(['Metric', 'Value'], collect($snap->metrics['quality']['totals'] + $snap->metrics['quality']['issues'])->map(fn ($v, $k) => [$k, $v])->values());

        return self::SUCCESS;
    }
}
