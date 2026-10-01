<?php

namespace App\Museum\Pipeline;

use App\Models\Museum\CrawlerJob;
use App\Models\Museum\CrawlerSource;
use App\Models\Museum\RawDocument;
use App\Models\Museum\Source;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Polite, policy-gated fetching into raw storage.
 *
 * A URL is fetched only if: the crawler source is enabled, its terms of use were reviewed
 * by a person, the bibliographic source's crawl_policy is "allowed", the URL matches the
 * allowed patterns, and robots.txt allows it. Requests to a host are spaced by the larger
 * of the configured delay and robots Crawl-delay. Identical bytes are never stored twice.
 */
class DocumentFetcher
{
    public function __construct(private RobotsPolicy $robots) {}

    /** @return array{status: string, raw?: RawDocument, reason?: string} */
    public function fetch(CrawlerSource $cs, string $url, ?CrawlerJob $job = null): array
    {
        if ($reason = $this->blockedReason($cs, $url)) {
            return ['status' => 'skipped', 'reason' => $reason];
        }
        $this->throttle($cs, $url);

        $previous = RawDocument::where('url_hash', hash('sha256', Source::canonicalUrl($url)))->latest('id')->first();
        $req = Http::withUserAgent(config('museum.crawler.user_agent'))
            ->timeout(config('museum.crawler.timeout', 60))
            ->withOptions(['stream' => false]);
        if ($previous?->etag) {
            $req = $req->withHeaders(['If-None-Match' => $previous->etag]);
        }
        $res = $req->get($url);
        if ($res->status() === 304) {
            return ['status' => 'unchanged', 'raw' => $previous];
        }
        if (! $res->successful()) {
            return ['status' => 'failed', 'reason' => 'HTTP '.$res->status()];
        }
        $body = $res->body();
        if (strlen($body) > config('museum.crawler.max_bytes')) {
            return ['status' => 'skipped', 'reason' => 'document exceeds max_bytes'];
        }

        return ['status' => 'fetched', 'raw' => $this->store($body, [
            'url' => $url,
            'final_url' => (string) ($res->effectiveUri() ?? $url),
            'http_status' => $res->status(),
            'content_type' => $res->header('Content-Type'),
            'etag' => $res->header('ETag') ?: null,
            'last_modified' => $res->header('Last-Modified') ?: null,
            'source_id' => $cs->source_id,
            'crawler_source_id' => $cs->id,
            'crawler_job_id' => $job?->id,
            'license' => $cs->source?->license ?? 'unknown',
        ])];
    }

    public function blockedReason(CrawlerSource $cs, string $url): ?string
    {
        if (! $cs->enabled) {
            return 'crawler source disabled';
        }
        if (! $cs->terms_reviewed) {
            return 'terms of use not reviewed';
        }
        if ($cs->source && $cs->source->crawl_policy !== 'allowed') {
            return 'source crawl_policy is '.$cs->source->crawl_policy;
        }
        if (! empty($cs->allowed_patterns)) {
            $ok = collect($cs->allowed_patterns)->contains(fn ($p) => Str::is($p, $url));
            if (! $ok) {
                return 'URL outside allowed patterns';
            }
        }
        if (! $this->robots->isAllowed($url)) {
            return 'disallowed by robots.txt (or robots.txt unavailable)';
        }

        return null;
    }

    /** Stores bytes in raw storage. Content-addressed: identical bytes reuse the existing record. */
    public function store(string $bytes, array $meta): RawDocument
    {
        $sha = hash('sha256', $bytes);
        $existing = RawDocument::where('sha256', $sha)->first();
        if ($existing) {
            return $existing;
        }
        $ext = $this->extension($meta['content_type'] ?? null, $meta['url'] ?? null);
        $host = isset($meta['url']) ? (parse_url($meta['url'], PHP_URL_HOST) ?: 'local') : 'local';
        $path = 'raw/'.Str::slug($host).'/'.now()->format('Y/m').'/'.$sha.'.'.$ext;
        $disk = config('museum.storage.raw_disk');
        Storage::disk($disk)->put($path, $bytes);

        return RawDocument::create(array_merge($meta, [
            'url_hash' => isset($meta['url']) ? hash('sha256', Source::canonicalUrl($meta['url'])) : null,
            'disk' => $disk,
            'path' => $path,
            'sha256' => $sha,
            'size_bytes' => strlen($bytes),
            'fetched_at' => now(),
            'status' => 'fetched',
        ]));
    }

    private function throttle(CrawlerSource $cs, string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $delay = max((int) $cs->crawl_delay_ms, (int) config('museum.crawler.min_delay_ms'), (int) $this->robots->crawlDelayMs($url));
        $key = 'museum:crawl:next:'.$host;
        Cache::lock('museum:crawl:lock:'.$host, 30)->block(30, function () use ($key, $delay) {
            $next = (float) Cache::get($key, 0);
            $now = microtime(true);
            if ($next > $now) {
                usleep((int) (($next - $now) * 1_000_000));
            }
            Cache::put($key, microtime(true) + $delay / 1000, 3600);
        });
    }

    private function extension(?string $contentType, ?string $url): string
    {
        $ct = strtolower(trim(explode(';', (string) $contentType)[0]));
        $map = [
            'application/pdf' => 'pdf', 'text/html' => 'html', 'application/xhtml+xml' => 'html', 'text/plain' => 'txt',
            'application/json' => 'json', 'application/sparql-results+json' => 'json', 'text/csv' => 'csv',
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/tiff' => 'tif', 'application/xml' => 'xml', 'text/xml' => 'xml',
        ];
        if (isset($map[$ct])) {
            return $map[$ct];
        }
        $ext = $url ? strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) : '';

        return preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'bin';
    }
}
