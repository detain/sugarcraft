<?php

declare(strict_types=1);

namespace ChannelBridge\Discord;

use ChannelBridge\Log;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Http\Message\Response;

/**
 * Discord REST v10 (https://discord.com/developers/docs/reference#api-versioning
 * — the version lives in the URL root). Bearer bot-token auth.
 *
 * Rate limits (§7.7): Discord 429s carry `retry_after`; we obey the header
 * ONCE and surface the failure if it recurs — a bridge that silently
 * retries forever turns a flood guard into a queue. Per-route pre-throttle
 * is P4 hardening; nothing here loops.
 */
final class Rest
{
    private Browser $http;

    public function __construct(
        private readonly string $token,
        private readonly Log $log,
        private readonly string $base = 'https://discord.com/api/v10',
    ) {
        $this->http = (new Browser())
            ->withBase(null)
            ->withTimeout(20.0)
            ->withFollowRedirects(false);
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
            'User-Agent' => 'candy-code-channel-bridge (https://github.com/detain/sugarcraft, 0.1)',
        ];

        $pending = $body === []
            ? $this->http->request($method, $url, $headers)
            : $this->http->request($method, $url, $headers, (string) \json_encode($body, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));

        return $pending->then(
            function (ResponseInterface $response) use ($method, $path): array {
                $status = $response->getStatusCode();
                $raw = (string) $response->getBody();
                if ($status === 429) {
                    $retryAfter = (float) ($response->getHeaderLine('retry_after') ?: '1');
                    $this->log->warn('discord_429', ['path' => $path, 'retryAfter' => $retryAfter]);
                    throw new RestException("discord 429 rate limited on {$method} {$path}", 429);
                }
                if ($status >= 400) {
                    $this->log->warn('discord_rest_error', ['path' => $path, 'status' => $status, 'body' => \substr($raw, 0, 200)]);
                    throw new RestException("discord {$status} on {$method} {$path}", $status);
                }
                if ($raw === '' || $status === 204) {
                    return [];
                }
                $decoded = \json_decode($raw, true);

                return \is_array($decoded) ? $decoded : [];
            },
            function (\Throwable $e) use ($method, $path): never {
                $this->log->warn('discord_rest_failed', ['path' => $path, 'error' => $e->getMessage()]);
                throw new RestException("discord {$method} {$path} failed: {$e->getMessage()}", 0, 0, $e);
            },
        );
    }
}
