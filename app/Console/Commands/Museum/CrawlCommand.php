<?php

namespace App\Console\Commands\Museum;

use App\Models\Museum\CrawlerSource;
use App\Museum\Jobs\FetchCrawlerSourceJob;
use Illuminate\Console\Command;

class CrawlCommand extends Command
{
    protected $signature = 'museum:crawl {id? : Crawler source id (default: all enabled, terms-reviewed sources)} {--sync}';

    protected $description = 'Queue crawls for enabled crawler sources whose terms were reviewed';

    public function handle(): int
    {
        $q = CrawlerSource::where('enabled', true)->where('terms_reviewed', true)->where('kind', '!=', 'wikidata_entitydata');
        if ($id = $this->argument('id')) {
            $q->whereKey($id);
        }
        foreach ($q->get() as $cs) {
            $this->option('sync') ? FetchCrawlerSourceJob::dispatchSync($cs->id) : FetchCrawlerSourceJob::dispatch($cs->id);
            $this->line('queued: '.$cs->name);
        }

        return self::SUCCESS;
    }
}
