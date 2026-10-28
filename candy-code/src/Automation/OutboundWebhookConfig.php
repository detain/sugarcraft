<?php

declare(strict_types=1);

namespace SugarCraft\Code\Automation;

/**
 * The parser for the user-tier `webhooksOut` map — the ONLY thing that
 * decides whether an outbound webhook endpoint exists. C-AGT-5.e.
 *
 * The shape is a map of name to settings, mirroring the inbound
 * `webhooks` doctrine in {@see \SugarCraft\Code\Server\ServerConfig::webhooksValue()}
 * (same name grammar, same fail-closed posture: every violation throws with
 * the offending name in the message, unknown settings are refusals not
 * warnings):
 *
 *     {"deploy": {"url": "https://host/hook", "secret": "...", "events": ["turn.completed"]}}
 *
 * THE URL LAW, and why it is not just "https or nothing". An outbound
 * delivery carries the event envelope and a valid signature — an attacker
 * who can make us POST to an arbitrary `http://` URL already holds a signed
 * request forgery with a long-lived secret baked in, and plain HTTP puts a
 * network observer between us and the receiver. So the default is https, and
 * the ONE exception is loopback: `http` is accepted only when the URL's host
 * is literally `localhost` or a loopback address (127.0.0.0/8, `::1`). The
 * exception is checked on the literal host text, never on a resolved IP —
 * resolution happens at connect time inside react/socket, and a name whose A
 * record can move is not "loopback" in any sense this class may promise.
 *
 * REDIRECTS ARE REFUSED AT THE TRANSPORT, AND THAT IS A CONFIG LAW TOO:
 * following a 301 hands the signature headers to wherever the host chain
 * points (they are request headers, not URL-scoped), so
 * {@see WebhookDispatcher} builds its browser with `withFollowRedirects(false)`
 * unconditionally; a 3xx answer is a failed delivery, not a new target. The
 * parser records the same intent on its side by refusing credentials in the
 * URL — userinfo in a webhook URL is a second, unmanaged credential, and the
 * secret is the one.
 *
 * Like the inbound side, the feature is DEFAULT-OFF BY CONSTRUCTION: no
 * endpoint exists until the operator's user config names one, and the
 * (later, 5.f) wiring reads only the user tier, so a cloned repo cannot mint
 * its own outbound door through project settings.
 */
final class OutboundWebhookConfig
{
    /** Same secret floor as the inbound hooks: the secret is the whole credential. */
    public const MIN_SECRET_BYTES = 16;

    /** The endpoint-name grammar, shared with the inbound webhook map. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** Settings one endpoint entry may name; anything else is a refusal. */
    private const KNOWN_SETTINGS = ['url', 'secret', 'events'];

    private function __construct()
    {
    }

    /**
     * @return array<string,array{url:string,secret:string,events:list<string>}>
     *
     * @throws \InvalidArgumentException on ANY violation, naming the endpoint
     */
    public static function parse(mixed $value): array
    {
        if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
            throw new \InvalidArgumentException('webhooksOut in the user config must be a map of endpoint name to its settings');
        }

        $endpoints = [];
        foreach ($value as $name => $endpoint) {
            if (!\is_string($name) || \preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException(\sprintf(
                    'outbound webhook "%s": the name must be 64 characters or fewer of a-z, 0-9, underscore or hyphen, starting with a letter or digit',
                    (string) $name,
                ));
            }
            if (!\is_array($endpoint) || \array_is_list($endpoint)) {
                throw new \InvalidArgumentException(\sprintf('outbound webhook "%s": its value must be a map of settings', $name));
            }

            $unknown = \array_values(\array_diff(\array_keys($endpoint), self::KNOWN_SETTINGS));
            if ($unknown !== []) {
                throw new \InvalidArgumentException(\sprintf('outbound webhook "%s": unknown setting "%s"', $name, (string) $unknown[0]));
            }

            $endpoints[$name] = [
                'url' => self::parseUrl($name, $endpoint['url'] ?? null),
                'secret' => self::parseSecret($name, $endpoint['secret'] ?? null),
                'events' => self::parseEvents($name, $endpoint['events'] ?? null),
            ];
        }

        return $endpoints;
    }

    /**
     * True for the host texts {@see parseUrl()} may pair with plain http:
     * `localhost` (any case) and the loopback address literals — IPv4 in the
     * whole 127.0.0.0/8 block and IPv6 `::1` in any spelling parse_url
     * hands back (brackets stripped, `0:0:...:1` normalised through
     * inet_pton).
     */
    public static function isLoopbackHost(string $host): bool
    {
        $host = \strtolower(\trim($host, '[]'));
        if ($host === 'localhost') {
            return true;
        }

        if (\filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return \str_starts_with($host, '127.');
        }

        if (\filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            $packed = \inet_pton($host);

            return $packed !== false && $packed === \inet_pton('::1');
        }

        return false;
    }

    private static function parseUrl(string $name, mixed $url): string
    {
        if (!\is_string($url) || \trim($url) === '') {
            throw new \InvalidArgumentException(\sprintf('outbound webhook "%s": url must be a non-empty string', $name));
        }
        $parts = \parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(\sprintf('outbound webhook "%s": url "%s" is not an absolute http(s) URL', $name, $url));
        }

        $scheme = \strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new \InvalidArgumentException(\sprintf('outbound webhook "%s": url scheme "%s" must be https, or http only to loopback', $name, $scheme));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(\sprintf('outbound webhook "%s": url must not carry credentials — the configured secret is the credential', $name));
        }
        if ($scheme === 'http' && !self::isLoopbackHost($parts['host'])) {
            throw new \InvalidArgumentException(\sprintf(
                'outbound webhook "%s": plain http is refused for host "%s" — https everywhere, http only to localhost or a loopback address',
                $name,
                $parts['host'],
            ));
        }

        return $url;
    }

    private static function parseSecret(string $name, mixed $secret): string
    {
        if (!\is_string($secret) || \strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'outbound webhook "%s": secret must be a string of at least %d characters — it is the only thing that proves the delivery came from this machine',
                $name,
                self::MIN_SECRET_BYTES,
            ));
        }

        return $secret;
    }

    /**
     * @return list<string>
     */
    private static function parseEvents(string $name, mixed $events): array
    {
        if (!\is_array($events) || !\array_is_list($events) || $events === []) {
            throw new \InvalidArgumentException(\sprintf('outbound webhook "%s": events must be a non-empty list of event type names', $name));
        }

        $seen = [];
        foreach ($events as $event) {
            if (!\is_string($event) || !\in_array($event, WebhookEvent::TYPES, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'outbound webhook "%s": event "%s" is not one of: %s',
                    $name,
                    \is_scalar($event) ? (string) $event : \get_debug_type($event),
                    \implode(', ', WebhookEvent::TYPES),
                ));
            }
            if (!isset($seen[$event])) {
                $seen[$event] = true;
            }
        }

        return \array_keys($seen);
    }
}
