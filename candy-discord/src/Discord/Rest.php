<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Discord;

use CandyCode\Bundles\Discord\Support\Log;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Socket\ConnectorInterface;
use SugarCraft\Code\Protocol\JsonRpc;

/**
 * Discord REST v10 (https://discord.com/developers/docs/reference#api-versioning
 * — the version lives in the URL root). Bearer bot-token auth.
 *
 * Rate limits (§7.7): a Discord 429 surfaces as a RestException(429) with
 * its Retry-After logged — nothing here sleeps or retries, because a bridge
 * that silently retries forever turns a flood guard into a queue. Per-route
 * pre-throttle is P4 hardening.
 */
final class Rest
{
    private Browser $http;

    public function __construct(
        private readonly string $token,
        private readonly Log $log,
        private readonly string $base = 'https://discord.com/api/v10',
        // Test seam only: media-fetch conformance needs a loopback stand-in
        // for the Discord CDN, and the https + media-host gate in
        // fetchBinary() must stay live while doing it — so the seam sits
        // BELOW the gate, at the dial, never beside it. Null = real world.
        ?ConnectorInterface $connector = null,
    ) {
        $this->http = (new Browser($connector))
            ->withBase(null)
            ->withTimeout(20.0)
            ->withFollowRedirects(false)
            // react/http defaults to REJECTING 4xx/5xx before a handler ever
            // sees the Response — that would kill the 429 rate-limit signal
            // (F3: every status arm below arrived as an opaque code-0
            // transport error) and turn Retry-After into prose in an
            // exception. Status is OUR decision to make, so the wire stays
            // honest down here.
            ->withRejectErrorResponse(false);
    }

    /** @return \React\Promise\PromiseInterface<array<string,mixed>> */
    public function get(string $path): \React\Promise\PromiseInterface
    {
        return $this->request('GET', $path);
    }

    /** @return \React\Promise\PromiseInterface<array<string,mixed>> */
    public function post(string $path, array $body = []): \React\Promise\PromiseInterface
    {
        return $this->request('POST', $path, $body);
    }

    /** @return \React\Promise\PromiseInterface<array<string,mixed>> */
    public function put(string $path, array $body): \React\Promise\PromiseInterface
    {
        return $this->request('PUT', $path, $body);
    }

    /** @return \React\Promise\PromiseInterface<array<string,mixed>> */
    public function patch(string $path, array $body): \React\Promise\PromiseInterface
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete(string $path): \React\Promise\PromiseInterface
    {
        return $this->request('DELETE', $path);
    }

    /** The application behind this bot token (its `id` is the loop guard). */
    public function application(): \React\Promise\PromiseInterface
    {
        return $this->get('/applications/@me');
    }

    /**
     * A channel message. `message_reference` threads a reply; `components`
     * carries the approval buttons; `allowed_mentions.none` stops the model
     * output from pinging humans.
     *
     * @param array<string,mixed> $extra
     *
     * @return \React\Promise\PromiseInterface<array<string,mixed>>
     */
    public function send(string $channelId, string $content, array $extra = []): \React\Promise\PromiseInterface
    {
        $body = ['content' => $content] + $extra;

        return $this->post("/channels/{$channelId}/messages", $body);
    }

    public function typing(string $channelId): \React\Promise\PromiseInterface
    {
        return $this->post("/channels/{$channelId}/typing");
    }

    /**
     * Fetch an attachment's raw bytes (§7.8 media intake). Attachment URLs
     * arrive from the GATEWAY message, carry a pre-signed query, and need
     * no bot header — but they must live on a Discord media host: a
     * non-Discord host here would mean a doctored payload, and dialing it
     * would hand our User-Agent to a stranger. https only; the transport is
     * capped HERE — `$maxBytes` rides into react/http's response buffer, so
     * a declared Content-Length over budget rejects with zero body reads
     * and a lying/absent header aborts at cap+1 consumed (never a full
     * materialization of a hostile stream). This method never loops or
     * retries; the caller's post-download strlen stays as belt.
     *
     * @return \React\Promise\PromiseInterface<string> raw bytes
     */
    public function fetchBinary(string $url, int $maxBytes): \React\Promise\PromiseInterface
    {
        $host = \strtolower((string) (\parse_url($url, \PHP_URL_HOST) ?: ''));
        if (\strtolower((string) (\parse_url($url, \PHP_URL_SCHEME) ?: '')) !== 'https'
            || !self::hostIsDiscordMedia($host)) {
            return \React\Promise\reject(new RestException(
                'discord media url refused (https + Discord media host required): ' . $host));
        }
        // Error prose carries the PATH only — the signed query is a bearer secret.
        $safeLabel = (string) (\parse_url($url, \PHP_URL_PATH) ?: '/');

        return $this->http->withResponseBuffer(\max(1, $maxBytes))->get($url)->then(
            function (ResponseInterface $response) use ($safeLabel): string {
                $status = $response->getStatusCode();
                if ($status >= 400) {
                    throw new RestException("discord media fetch {$status} for {$safeLabel}", $status);
                }

                return (string) $response->getBody();
            },
            function (\Throwable $e) use ($safeLabel): never {
                throw new RestException("discord media fetch failed for {$safeLabel}: {$e->getMessage()}", 0, 0, $e);
            },
        );
    }

    private static function hostIsDiscordMedia(string $host): bool
    {
        foreach (['discord.com', 'discord.net', 'discordapp.com', 'discordapp.net'] as $domain) {
            if ($host === $domain || \str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An interaction answer. Type 4 = CHANNEL_MESSAGE_WITH_SOURCE, type 7 =
     * deferred update; flags 64 = ephemeral (§7.5: approvals are single-use
     * and answered in the channel that asked).
     */
    public function respondInteraction(string $id, string $token, int $type, array $data, int $flags = 0): \React\Promise\PromiseInterface
    {
        $body = \array_filter([
            'type' => $type,
            'data' => (object) $data,
            'flags' => $flags !== 0 ? $flags : null,
        ], static fn (mixed $v): bool => $v !== null);

        return $this->post("/interactions/{$id}/{$token}/callback", $body);
    }

    /**
     * Native slash deployment (owner ruling D7=7a): a global PUT on boot.
     * A guild-scoped PUT is the dev-loop alternative; §7.6's mention
     * fallback covers the window before deploy lands either way.
     *
     * @param list<array<string,mixed>> $commands
     */
    public function deployGlobalCommands(string $applicationId, array $commands): \React\Promise\PromiseInterface
    {
        return $this->put("/applications/{$applicationId}/commands", $commands);
    }

    /**
     * @param list<array<string,mixed>> $commands
     */
    public function deployGuildCommands(string $applicationId, string $guildId, array $commands): \React\Promise\PromiseInterface
    {
        return $this->put("/applications/{$applicationId}/guilds/{$guildId}/commands", $commands);
    }

    /** @return \React\Promise\PromiseInterface<array<string,mixed>> */
    private function request(string $method, string $path, array $body = []): \React\Promise\PromiseInterface
    {
        $url = $this->base . $path;
        $headers = [
            'Authorization' => 'Bot ' . $this->token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'candy-code-discord-bundle (https://github.com/detain/sugarcraft, 0.1)',
        ];

        $pending = $body === []
            ? $this->http->request($method, $url, $headers)
            : $this->http->request($method, $url, $headers, (string) \json_encode($body, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));

        return $pending->then(
            function (ResponseInterface $response) use ($method, $path): array {
                $status = $response->getStatusCode();
                $raw = (string) $response->getBody();
                if ($status === 429) {
                    $retryAfter = (float) ($response->getHeaderLine('Retry-After') ?: '1');
                    $this->log->warn('discord_429', ['path' => $path, 'retryAfter' => $retryAfter]);
                    throw new RestException("discord 429 rate limited on {$method} {$path}", 429);
                }
                if ($status >= 400) {
                    // The bot token must never reach a log line, even if an error body echoes it back.
                    $body = \str_replace($this->token, '[redacted]', \substr($raw, 0, 200));
                    $this->log->warn('discord_rest_error', ['path' => $path, 'status' => $status, 'body' => $body]);
                    throw new RestException("discord {$status} on {$method} {$path}", $status);
                }
                if ($raw === '' || $status === 204) {
                    return [];
                }
                try {
                    $decoded = \json_decode($raw, true, JsonRpc::MAX_DEPTH, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    // Corrupt-but-nonempty is a failure, not an empty result:
                    // the caller must see the path, not a silent [].
                    throw new RestException("discord {$method} {$path} returned a malformed body: {$e->getMessage()}", $status, 0, $e);
                }
                if (!\is_array($decoded)) {
                    // Every REST payload anyone consumes is an object or a
                    // list; a 2xx carrying a JSON scalar is corrupt in shape
                    // even when it decodes.
                    throw new RestException("discord {$method} {$path} returned a non-array body", $status);
                }

                return $decoded;
            },
            function (\Throwable $e) use ($method, $path): never {
                $this->log->warn('discord_rest_failed', ['path' => $path, 'error' => $e->getMessage()]);
                throw new RestException("discord {$method} {$path} failed: {$e->getMessage()}", 0, 0, $e);
            },
        );
    }
}
