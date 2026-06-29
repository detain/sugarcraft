<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

final readonly class WebFetch implements Tool
{
    private const MAX_REDIRECTS = 3;
    private const MAX_RESPONSE_SIZE = 2 * 1024 * 1024;

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
        'fc00::/7',
        'fe80::/10',
    ];

    public function name(): string
    {
        return 'WebFetch';
    }
    public function description(): string
    {
        return 'Fetch content from a URL';
    }
    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
        ],
        'required' => ['url'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $url = $args['url'] ?? '';

        if ($url === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: url cannot be empty',
                isError: true,
            );
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: url must start with http:// or https://',
                isError: true,
            );
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: invalid URL',
                isError: true,
            );
        }

        $host = $parsed['host'];

        if (in_array(strtolower($host), self::BLOCKED_HOSTNAMES, true)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: fetching from localhost is not allowed',
                isError: true,
            );
        }

        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: fetching from private/link-local addresses is not allowed',
                isError: true,
            );
        }

        $redirectCount = 0;
        $finalUrl = $url;
        $content = '';

        while ($redirectCount <= self::MAX_REDIRECTS) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'ignore_errors' => true,
                    'max_redirects' => 0,
                ],
            ]);

            $chunk = @file_get_contents($finalUrl, false, $context);
            if ($chunk === false) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: "Error fetching URL: $finalUrl",
                    isError: true,
                );
            }

            $content .= $chunk;

            if (strlen($content) > self::MAX_RESPONSE_SIZE) {
                $content = substr($content, 0, self::MAX_RESPONSE_SIZE) . "\n... [truncated]";
                break;
            }

            if (!isset($http_response_header[0])) {
                break;
            }

            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $http_response_header[0], $m)) {
                $code = (int) $m[1];
                if ($code >= 300 && $code < 400 && isset($parsed['host'])) {
                    $redirectCount++;
                    if ($redirectCount > self::MAX_REDIRECTS) {
                        break;
                    }
                    $newUrl = $this->resolveRedirectUrl($finalUrl, $http_response_header);
                    if ($newUrl === null) {
                        break;
                    }
                    $newParsed = parse_url($newUrl);
                    if ($newParsed === false || !isset($newParsed['host'])) {
                        break;
                    }
                    $newHost = $newParsed['host'];
                    if (in_array(strtolower($newHost), self::BLOCKED_HOSTNAMES, true)) {
                        return new ToolResult(
                            toolCallId: $args['id'] ?? '',
                            content: 'Error: redirect to localhost is not allowed',
                            isError: true,
                        );
                    }
                    $newIp = gethostbyname($newHost);
                    if ($newIp !== $newHost && filter_var($newIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                        return new ToolResult(
                            toolCallId: $args['id'] ?? '',
                            content: 'Error: redirect to private/link-local address is not allowed',
                            isError: true,
                        );
                    }
                    $finalUrl = $newUrl;
                    continue;
                }
                break;
            } else {
                break;
            }
        }

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $content,
            isError: false,
        );
    }

    private function resolveRedirectUrl(string $originalUrl, array $headers): ?string
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), 'location:')) {
                $location = trim(substr($header, 9));
                if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
                    return $location;
                }
                if (str_starts_with($location, '/')) {
                    $parsed = parse_url($originalUrl);
                    if ($parsed !== false && isset($parsed['scheme'], $parsed['host'])) {
                        return $parsed['scheme'] . '://' . $parsed['host'] . $location;
                    }
                }
                return $location;
            }
        }
        return null;
    }
}
