<?php

namespace App\Museum\Pipeline;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * robots.txt compliance (RFC 9309): groups for our agent take precedence over "*",
 * longest matching rule wins, Allow wins ties, "*" and "$" wildcards supported.
 * If robots.txt cannot be fetched due to a server error, crawling is disallowed;
 * a 4xx (no robots.txt) means everything is allowed, per the RFC.
 */
class RobotsPolicy
{
    public function isAllowed(string $url): bool
    {
        $rules = $this->rulesFor($url);
        if ($rules === null) {
            return false;
        }
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');

        return self::evaluate($rules['rules'], $path);
    }

    public function crawlDelayMs(string $url): ?int
    {
        $rules = $this->rulesFor($url);

        return isset($rules['delay']) ? (int) ($rules['delay'] * 1000) : null;
    }

    /** @return array{rules: list<array{0: string, 1: string}>, delay: ?float}|null */
    private function rulesFor(string $url): ?array
    {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return null;
        }
        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return Cache::remember('museum:robots:'.sha1($origin), now()->addHours(config('museum.crawler.robots_cache_hours', 24)), function () use ($origin) {
            try {
                $res = Http::withUserAgent(config('museum.crawler.user_agent'))->timeout(20)->get($origin.'/robots.txt');
            } catch (\Throwable) {
                return null;
            }
            if ($res->status() >= 500) {
                return null;
            }
            if ($res->status() >= 400) {
                return ['rules' => [], 'delay' => null];
            }

            return self::parse($res->body(), config('museum.crawler.robots_agent'));
        });
    }

    public static function parse(string $body, string $agent): array
    {
        $agent = strtolower($agent);
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                if (! $lastWasAgent) {
                    $groups[] = ['agents' => [], 'rules' => [], 'delay' => null];
                    $current = count($groups) - 1;
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;
            if ($current === null) {
                continue;
            }
            if ($key === 'allow' || $key === 'disallow') {
                if ($value !== '' || $key === 'allow') {
                    $groups[$current]['rules'][] = [$key, $value];
                }
            } elseif ($key === 'crawl-delay' && is_numeric($value)) {
                $groups[$current]['delay'] = (float) $value;
            }
        }
        $specific = array_values(array_filter($groups, fn ($g) => collect($g['agents'])->contains(fn ($a) => $a !== '*' && str_contains($agent, $a))));
        $chosen = $specific ?: array_values(array_filter($groups, fn ($g) => in_array('*', $g['agents'], true)));
        $rules = [];
        $delay = null;
        foreach ($chosen as $g) {
            $rules = array_merge($rules, $g['rules']);
            $delay = $g['delay'] ?? $delay;
        }

        return ['rules' => $rules, 'delay' => $delay];
    }

    public static function evaluate(array $rules, string $path): bool
    {
        $best = null;
        $bestLen = -1;
        foreach ($rules as [$type, $pattern]) {
            if ($pattern === '') {
                continue;
            }
            $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')).'#';
            if (preg_match($regex, $path)) {
                $len = strlen($pattern);
                if ($len > $bestLen || ($len === $bestLen && $type === 'allow')) {
                    $best = $type;
                    $bestLen = $len;
                }
            }
        }

        return $best !== 'disallow';
    }
}
