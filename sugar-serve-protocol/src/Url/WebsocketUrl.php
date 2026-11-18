<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Url;

/**
 * Turns the address a server prints (or a browser opens) into the ws(s) URL
 * the serve protocol is dialled at.
 *
 * The pure half of candy-code's `Attach::websocketUrl`: the host keeps its
 * method and delegates here, so the URL rules have one home.
 */
final class WebsocketUrl
{
    /** The path the WebSocket is served on; any path given is replaced. */
    public const WS_PATH = '/ws';

    private function __construct()
    {
    }

    /**
     * `ws://host:port/ws` for an `http(s)://` or `ws(s)://` address, or null
     * when $url is neither. A bracketed IPv6 host is kept bracketed.
     */
    public static function from(string $url): ?string
    {
        $parts = \parse_url(\trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = match (\strtolower($parts['scheme'])) {
            'http', 'ws' => 'ws',
            'https', 'wss' => 'wss',
            default => null,
        };
        if ($scheme === null || $parts['host'] === '') {
            return null;
        }
        $host = \str_contains($parts['host'], ':') && !\str_starts_with($parts['host'], '[') ? '[' . $parts['host'] . ']' : $parts['host'];

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . self::WS_PATH;
    }
}
