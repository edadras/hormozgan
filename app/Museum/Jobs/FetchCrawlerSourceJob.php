<?php

namespace App\Museum\Jobs;

use App\Models\Museum\CrawlerJob;
use App\Models\Museum\CrawlerSource;
use App\Museum\Pipeline\DocumentFetcher;
use App\Museum\Pipeline\TextExtractor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Crawls one crawler source: its seed URLs, and for "http_listing" sources the document
 * links found on the seed pages (restricted to allowed patterns and max_documents).
 */
class FetchCrawlerSourceJob extends MuseumJob
{
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $crawlerSourceId)
    {
        $this->onMuseumQueue('crawl');
    }

    public function handle(DocumentFetcher $fetcher, TextExtractor $text): void
    {
        $cs = CrawlerSource::with('source')->findOrFail($this->crawlerSourceId);
        $job = CrawlerJob::create(['crawler_source_id' => $cs->id, 'status' => 'running', 'started_at' => now()]);
        $log = [];
        $queue = array_values(array_unique($cs->seed_urls ?: array_filter([$cs->base_url])));
        $seen = [];
        $count = ['fetched' => 0, 'skipped' => 0, 'unchanged' => 0, 'errors' => 0];

        while ($queue && $cs->max_documents > $count['fetched'] + $count['unchanged']) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            try {
                $res = $fetcher->fetch($cs, $url, $job);
            } catch (\Throwable $e) {
                $res = ['status' => 'failed', 'reason' => $e->getMessage()];
            }
            $key = match ($res['status']) {
                'fetched' => 'fetched', 'unchanged' => 'unchanged', 'skipped' => 'skipped', default => 'errors',
            };
            $count[$key]++;
            $log[] = ['url' => $url, 'status' => $res['status'], 'reason' => $res['reason'] ?? null];
            if (! empty($res['raw']) && $res['status'] === 'fetched') {
                ProcessRawDocumentJob::dispatch($res['raw']->id);
                if ($cs->kind === 'http_listing' && str_ends_with($res['raw']->path, '.html')) {
                    foreach ($this->links(Storage::disk($res['raw']->disk)->get($res['raw']->path), $url) as $link) {
                        if (! empty($cs->allowed_patterns) && collect($cs->allowed_patterns)->contains(fn ($p) => Str::is($p, $link))) {
                            $queue[] = $link;
                        }
                    }
                }
            }
        }
        $job->forceFill([
            'status' => 'completed', 'finished_at' => now(), 'log' => array_slice($log, -500),
            'documents_fetched' => $count['fetched'], 'documents_skipped' => $count['skipped'],
            'documents_unchanged' => $count['unchanged'], 'errors' => $count['errors'],
        ])->save();
        $cs->forceFill(['last_run_at' => now()])->save();
    }

    /** Document-like links (pdf, images, html) resolved against the page URL. */
    private function links(string $html, string $base): array
    {
        preg_match_all('/href\s*=\s*["\']([^"\'#]+)["\']/i', $html, $m);
        $out = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href);
            if (str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $abs = preg_match('#^https?://#i', $href) ? $href : $this->resolve($base, $href);
            if ($abs) {
                $out[] = $abs;
            }
        }

        return array_values(array_unique($out));
    }

    private function resolve(string $base, string $rel): ?string
    {
        $p = parse_url($base);
        if (empty($p['host'])) {
            return null;
        }
        $origin = ($p['scheme'] ?? 'https').'://'.$p['host'];
        if (str_starts_with($rel, '//')) {
            return ($p['scheme'] ?? 'https').':'.$rel;
        }
        if (str_starts_with($rel, '/')) {
            return $origin.$rel;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');

        return $origin.$dir.$rel;
    }
}
