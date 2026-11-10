<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Support;

use RuntimeException;

/**
 * Resolves the Teams OAuth token root and the Bot Framework JWKS URL (§8.1).
 *
 * The override settings exist so a local test proxy (or an air-gapped
 * mirror of the login endpoints) can front Microsoft. That same knob is a
 * secret-leak shape: the app password rides the token exchange and the
 * verified roster rides the JWKS fetch, so a plain-HTTP override must never
 * silently point at a remote host. https/wss always pass; http/ws pass ONLY
 * for a loopback host AND only when the operator explicitly opted in via
 * channels.msteams.transport.insecureLoopbackProxy (the proxy-on-localhost
 * case, where the operator controls the last hop).
 *
 * Outbound conversation posts are NOT resolved here: their base is the
 * activity's serviceUrl (§8.6), which Rest validates as https on its own.
 *
 * Fail loud at start, not quiet at first send: a refused override throws
 * with the exact setting name that would authorize it.
 */
final class Endpoint
{
    public const DEFAULT_AUTH = 'https://login.microsoftonline.com';
    public const DEFAULT_JWKS = 'https://login.botframework.com/v1/.well-known/keys';

    /**
     * The OAuth token root (§8.1): override wins, empty means default.
     *
     * @throws RuntimeException when the override is not an allowed endpoint
     */
    public static function authBase(string $override, bool $insecureLoopbackProxy = false): string
    {
        return self::resolve($override, $insecureLoopbackProxy, self::DEFAULT_AUTH);
    }

    /**
     * The signing-key roster URL; same transport discipline as authBase().
     *
     * @throws RuntimeException when the override is not an allowed endpoint
     */
    public static function jwksUrl(string $override, bool $insecureLoopbackProxy = false): string
    {
        return self::resolve($override, $insecureLoopbackProxy, self::DEFAULT_JWKS);
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
                'msteams-bundle: endpoint override "' . $override . '" is not an absolute https URL.'
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
                'msteams-bundle: plain-' . $scheme . ' loopback override "' . $override . '" needs '
                . 'channels.msteams.transport.insecureLoopbackProxy = true (a local proxy you control).'
            );
        }

        throw new RuntimeException(
            'msteams-bundle: endpoint override "' . $override . '" must be https'
            . ($scheme === 'http' || $scheme === 'ws' ? '; plain ' . $scheme . ' is only ever accepted for a loopback proxy' : '')
            . '.'
        );
    }
}
