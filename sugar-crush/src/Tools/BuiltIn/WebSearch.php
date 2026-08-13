<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Deliberately NOT final, unlike its sibling built-ins: the class performs its
 * own HTTP fetch with no injected client, so subclassing is the only seam the
 * tests have to stub a response. Marking it final breaks every WebSearch test
 * with ClassIsFinalException. Injecting a client would be the tidier fix and
 * would let this join the others as final.
 */
class WebSearch implements Tool
{
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        '127.0.0.1',
        '::1',
    ];

    private const BLOCKED_IP_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    private const MAX_RESPONSE_SIZE = 5 * 1024 * 1024; // 5MB

    private ?string $endpoint;

    public function __construct(
        ?string $endpoint = null,
        private int $timeout = 30,
        private int $maxResults = 10,
    ) {
        $this->endpoint = $endpoint ?? getenv('SUGARCRUSH_SEARCH_ENDPOINT') ?: 'http://skynet2.interserver.net:8080/search';
    }

    public function name(): string
    {
        return 'WebSearch';
    }

    public function description(): string
    {
        return 'Search the web for information via a configurable SearXNG endpoint. Returns answers, top results with snippets, suggestions, and corrections.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The search query (e.g. "PHP 8.3 release date")'],
                'safesearch' => ['type' => 'integer', 'description' => 'Safe search filter: 0 for none, 1 for moderate, 2 for strict', 'minimum' => 0, 'maximum' => 2],
                'time_range' => ['type' => 'string', 'description' => 'Time range limit: day, month, or year', 'enum' => ['day', 'month', 'year']],
                'description' => ['type' => 'string', 'description' => 'Clear, concise 5-10 word description in active voice of what this search is for (e.g. "Find PHP 8.3 release notes", not "searches the web")'],
            ],
            'required' => ['query', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $query = (string) ($args['query'] ?? '');

        if ($query === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: query cannot be empty',
                isError: true,
            );
        }

        if (mb_strlen($query) > 2000) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: query exceeds maximum length of 2000 characters',
                isError: true,
            );
        }

        $params = [
            'q' => $query,
            'format' => 'json',
        ];

        if (isset($args['safesearch'])) {
            $params['safesearch'] = (int) $args['safesearch'];
        }

        if (isset($args['time_range'])) {
            $params['time_range'] = $args['time_range'];
        }

        $url = $this->endpoint . '?' . http_build_query($params);

        if (!str_starts_with($this->endpoint, 'http://') && !str_starts_with($this->endpoint, 'https://')) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: search endpoint must use http:// or https://',
                isError: true,
            );
        }

        $parsedEndpoint = parse_url($this->endpoint);
        if ($parsedEndpoint !== false && isset($parsedEndpoint['host'])) {
            $endpointHost = $parsedEndpoint['host'];
            if (in_array(strtolower($endpointHost), self::BLOCKED_HOSTNAMES, true)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: search endpoint cannot be localhost',
                    isError: true,
                );
            }
            if ($this->targetsBlockedAddress($endpointHost)) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'Error: search endpoint cannot resolve to a private or link-local address',
                    isError: true,
                );
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $start = hrtime(true);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];

        if ($body !== false && strlen($body) > self::MAX_RESPONSE_SIZE) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: search response exceeds maximum size limit',
                isError: true,
            );
        }

        if ($body === false) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: failed to connect to search endpoint. Check network connectivity.',
                isError: true,
            );
        }

        if (isset($responseHeaders[0]) && preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $responseHeaders[0], $m)) {
            $code = (int) $m[1];
            if ($code >= 400 && $code < 500) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: "Error: HTTP {$code} — bad request to search endpoint. Check endpoint configuration.",
                    isError: true,
                );
            }
            if ($code >= 500) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: "Error: HTTP {$code} — search endpoint server error. Try again later.",
                    isError: true,
                );
            }
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: search endpoint returned invalid JSON response',
                isError: true,
            );
        }

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $this->formatResults($data),
            isError: false,
            durationMs: $durationMs,
        );
    }

    private function targetsBlockedAddress(string $host): bool
    {
        $candidate = strtolower(trim($host, '[]'));

        $ip = filter_var($candidate, FILTER_VALIDATE_IP) !== false
            ? $candidate
            : gethostbyname($candidate);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach (self::BLOCKED_IP_RANGES as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = ~(0xFF >> $remainder) & 0xFF;

        return ((ord($ipBin[$fullBytes]) ^ ord($subnetBin[$fullBytes])) & $mask) === 0;
    }

    /**
     * Keep only the scalar entries of a remote list, stringified.
     *
     * SearXNG's list-shaped fields are not guaranteed to hold strings, and
     * array_map('strval', ...) over a nested array raises "Array to string
     * conversion" and emits the literal word "Array" into the model's context.
     *
     * @param  array<mixed> $values
     * @return list<string>
     */
    private static function flattenScalars(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $out[] = (string) $value;
            }
        }

        return $out;
    }

    private function formatResults(array $data): string
    {
        $parts = [];

        if (isset($data['query'])) {
            $parts[] = "Search query: {$data['query']}";
        }

        if (!empty($data['answers'])) {
            $parts[] = "Direct answers:";
            foreach ($data['answers'] as $answer) {
                if (is_array($answer)) {
                    $parts[] = '  • ' . ($answer['answer'] ?? json_encode($answer));
                } elseif (is_string($answer)) {
                    $parts[] = '  • ' . $answer;
                } elseif (is_bool($answer)) {
                    $parts[] = '  • ' . ($answer ? 'true' : 'false');
                } elseif ($answer === null) {
                    $parts[] = '  • (no answer)';
                } else {
                    $parts[] = '  • ' . (is_scalar($answer) ? (string) $answer : json_encode($answer));
                }
            }
        }

        if (!empty($data['results'])) {
            $count = count($data['results']);
            $parts[] = "Search results ({$count}):";
            foreach (array_slice($data['results'], 0, $this->maxResults) as $i => $result) {
                $i++;
                $title = $result['title'] ?? 'Untitled';
                $url = $result['url'] ?? 'No URL';
                $content = (string) ($result['content'] ?? '');
                // mb_* because a byte-based cut lands mid-codepoint on any
                // non-ASCII snippet and puts a broken character in the transcript.
                if (mb_strlen($content) > 200) {
                    $content = mb_substr($content, 0, 200) . '...';
                }
                $parts[] = "  {$i}. {$title}";
                $parts[] = "     URL: {$url}";
                if ($content !== '') {
                    $parts[] = "     {$content}";
                }
            }
            if ($count > $this->maxResults) {
                $parts[] = "  ... and " . ($count - $this->maxResults) . " more results";
            }
        }

        if (!empty($data['suggestions'])) {
            $parts[] = 'Suggestions: ' . implode(', ', self::flattenScalars($data['suggestions']));
        }

        if (!empty($data['corrections'])) {
            $parts[] = 'Corrections: ' . implode(', ', self::flattenScalars($data['corrections']));
        }

        if (!empty($data['infoboxes'])) {
            $parts[] = "Info boxes:";
            foreach ($data['infoboxes'] as $ib) {
                $label = $ib['infobox'] ?? $ib['title'] ?? 'Information';
                $parts[] = "  • {$label}";
            }
        }

        if (!empty($data['unresponsive_engines'])) {
            // SearXNG reports these as [engine, reason] PAIRS, not strings, so a
            // bare implode() raises "Array to string conversion" and prints the
            // word "Array" to the model. Flatten each entry to "engine (reason)".
            $engines = [];
            foreach ($data['unresponsive_engines'] as $entry) {
                if (is_array($entry)) {
                    $name = (string) ($entry[0] ?? 'unknown');
                    $reason = isset($entry[1]) && is_scalar($entry[1]) ? (string) $entry[1] : '';
                    $engines[] = $reason === '' ? $name : "{$name} ({$reason})";
                } elseif (is_scalar($entry)) {
                    $engines[] = (string) $entry;
                }
            }
            if ($engines !== []) {
                $parts[] = 'Note: Some search engines were unavailable: ' . implode(', ', $engines);
            }
        }

        return count($parts) > 0 ? implode("\n", $parts) : 'No results found for the query.';
    }
}
