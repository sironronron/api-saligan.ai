<?php

namespace App\Services\Crawler;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RobotsTxt
{
    private const CACHE_TTL = 86400;

    public function allows(string $url): bool
    {
        $robotsUrl = $this->robotsUrl($url);

        if ($robotsUrl === null) {
            return true;
        }

        $rules = Cache::remember("crawler:robots:{$robotsUrl}", self::CACHE_TTL, function () use ($robotsUrl): array {
            return $this->fetchRules($robotsUrl);
        });

        return ! $this->isDisallowed($url, $rules);
    }

    private function robotsUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if (! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        return $parsed['scheme'].'://'.$parsed['host'].'/robots.txt';
    }

    /**
     * @return array{user_agents: array<string, string[]>, group: string[]}
     */
    private function fetchRules(string $robotsUrl): array
    {
        $userAgent = config('saligan.crawler.user_agent');

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => $userAgent])
                ->get($robotsUrl);
        } catch (ConnectionException) {
            return ['user_agents' => [], 'group' => []];
        }

        if (! $response->ok()) {
            return ['user_agents' => [], 'group' => []];
        }

        return $this->parse($response->body());
    }

    /**
     * @param  array{user_agents: array<string, string[]>, group: string[]}  $rules
     */
    private function isDisallowed(string $url, array $rules): bool
    {
        if (! empty($rules['group'])) {
            return $this->pathMatchesAny($url, $rules['group']);
        }

        $agentKey = strtolower($rules['user_agents']['name'] ?? '');

        if ($agentKey !== '' && isset($rules['user_agents'][$agentKey])) {
            return $this->pathMatchesAny($url, $rules['user_agents'][$agentKey]);
        }

        return false;
    }

    /**
     * @param  string[]  $disallows
     */
    private function pathMatchesAny(string $url, array $disallows): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach ($disallows as $disallow) {
            $rule = trim($disallow);

            if ($rule === '') {
                continue;
            }

            if ($rule === '/') {
                return true;
            }

            if ($this->wildcardMatch($rule, $path)) {
                return true;
            }
        }

        return false;
    }

    private function wildcardMatch(string $pattern, string $path): bool
    {
        $quoted = preg_quote($pattern, '~');
        $quoted = str_replace(['\*', '\$'], ['.*', '$'], $quoted);

        return (bool) preg_match("~^{$quoted}~", $path);
    }

    /**
     * Parse a robots.txt body into a simple allowlist map.
     *
     * @return array{user_agents: array<string, string[]>, group: string[]}
     */
    private function parse(string $body): array
    {
        $rules = ['user_agents' => [], 'group' => []];
        $currentAgents = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);

            if (Str::lower($key) === 'user-agent') {
                $currentAgents = [$value];

                if (! isset($rules['user_agents']['name'])) {
                    $rules['user_agents']['name'] = $value;
                }

                $rules['user_agents'][Str::lower($value)] = [];

                continue;
            }

            if (Str::lower($key) === 'disallow' && $value !== '') {
                foreach ($currentAgents as $agent) {
                    $rules['user_agents'][Str::lower($agent)][] = $value;
                }
            }
        }

        $name = Str::lower($rules['user_agents']['name'] ?? '');

        if ($name !== '' && isset($rules['user_agents'][$name])) {
            $rules['group'] = $rules['user_agents'][$name];
        }

        return $rules;
    }
}
