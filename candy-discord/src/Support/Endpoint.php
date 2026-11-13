<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Support;

use RuntimeException;
use SugarCraft\Code\Lang;

/**
 * Resolves the Discord API root and gateway websocket URL (§7.1).
 *
 * The override settings exist so a self-hosted mirror or a local test
 * proxy can front Discord. That same knob is a token-leak shape: a bot
 * token rides every request, so a plain-HTTP override must never silently
 * point at a remote host. https/wss always pass; http/ws pass ONLY for a
 * loopback host AND only when the operator explicitly opted in via
 * channels.discord.transport.insecureLoopbackProxy (the proxy-on-localhost
 * case, where the operator controls the last hop).
 *
 * Fail loud at start, not quiet at first send: a refused override throws
 * with the exact setting name that would authorize it.
 */
final class Endpoint
{
    public const DEFAULT_API = 'https://discord.com/api/v10';
    public const DEFAULT_GATEWAY = 'wss://gateway.discord.gg/?v=10&encoding=json';

    /**
     * The versioned REST root (§7.1): override wins, empty means default.
     *
     * @throws RuntimeException when the override is not an allowed endpoint
     */
    public static function apiBase(string $override, bool $insecureLoopbackProxy = false): string
    {
        return self::resolve($override, $insecureLoopbackProxy, self::DEFAULT_API);
    }

    /**
     * The gateway websocket URL; same transport discipline as apiBase().
     *
     * @throws RuntimeException when the override is not an allowed endpoint
     */
    public static function gatewayUrl(string $override, bool $insecureLoopbackProxy = false): string
    {
        return self::resolve($override, $insecureLoopbackProxy, self::DEFAULT_GATEWAY);
    }

    /**
     * True for localhost, ::1 and any 127.x.y.z address (IPv6 brackets stripped).
     */
    public static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        return preg_match('/^127\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/', $host) === 1;
    }

    /**
     * Is a READY-supplied `resume_gateway_url` an address the operator
     * actually dialed? The resume legs re-send the bot token and session
     * id to this URL on every reconnect, so an unvetted handback is a
     * credential-exfil vector and a `ws://` handback is a plaintext
     * downgrade around resolve()'s door.
     *
     * Trusted means: host equals or is a subdomain of the dialed host,
     * the port matches exactly, and the scheme is wss — or ws when the
     * dialed target is itself a loopback ws proxy (the mirror may hand
     * back its own address, nothing else).
     */
    public static function resumeUrlTrusted(string $candidate, string $dialedUrl): bool
    {
        $c = parse_url(trim($candidate));
        $d = parse_url(trim($dialedUrl));
        $cScheme = strtolower((string) ($c['scheme'] ?? ''));
        $cHost = strtolower((string) ($c['host'] ?? ''));
        $dScheme = strtolower((string) ($d['scheme'] ?? ''));
        $dHost = strtolower((string) ($d['host'] ?? ''));
        if ($cHost === '' || $dHost === '' || $cScheme === '') {
            return false;
        }

        if ($cHost !== $dHost && !str_ends_with($cHost, '.' . $dHost)) {
            return false;
        }
        if (($c['port'] ?? null) !== ($d['port'] ?? null)) {
            return false;
        }
        if ($cScheme === 'wss') {
            return true;
        }

        return $cScheme === 'ws' && $dScheme === 'ws' && self::isLoopback($cHost);
    }

    private static function resolve(string $override, bool $insecureLoopbackProxy, string $default): string
    {
        $override = trim($override);
        if ($override === '') {
            return $default;
        }

        $parts = parse_url($override);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($scheme === '' || $host === '') {
            throw new RuntimeException(
                Lang::t('channels.discord.endpoint.not_absolute', ['override' => $override])
            );
        }

        $scheme = strtolower($scheme);
        if ($scheme === 'https' || $scheme === 'wss') {
            return $override;
        }

        if (($scheme === 'http' || $scheme === 'ws') && self::isLoopback($host) && $insecureLoopbackProxy) {
            return $override;
        }

        if (($scheme === 'http' || $scheme === 'ws') && self::isLoopback($host)) {
            throw new RuntimeException(
                Lang::t('channels.discord.endpoint.loopback_opt_in', ['scheme' => $scheme, 'override' => $override])
            );
        }

        throw new RuntimeException(
            $scheme === 'http' || $scheme === 'ws'
                ? Lang::t('channels.discord.endpoint.must_be_secure_plain', ['override' => $override, 'scheme' => $scheme])
                : Lang::t('channels.discord.endpoint.must_be_secure', ['override' => $override])
        );
    }
}
