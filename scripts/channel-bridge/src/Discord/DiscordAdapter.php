<?php

declare(strict_types=1);

namespace ChannelBridge\Discord;

use ChannelBridge\Bridge;
use ChannelBridge\ChannelInterface;
use ChannelBridge\Keys;
use ChannelBridge\Log;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Discord's ChannelInterface: gateway dispatches in, REST out (§7).
 *
 * Inbound discipline, in order: ignore our own author id (bot-loop guard);
 * drop foreign bots unless `allowBots`; apply the guild/channel policy
 * (`allowBots` never overrides a policy that has no entry); resolve the
 * §9.1 key — a thread's channel id IS the key, which is how "one thread,
 * one session" falls out for free; DMs follow the `dmScope` ladder.
 * Commands ride BOTH spellings: a deployed slash interaction (§7.6, ruling
 * D7=7a) or the `/word` text fallback; everything else is a prompt.
 *
 * Outbound: plain content, `allowed_mentions.parse = []` so model output
 * cannot ping humans, chunked at 2000/17 (§7.7) by the Bridge before it
 * gets here, `message_reference` keeping replies inside the thread that
 * asked.
 */
final class DiscordAdapter implements ChannelInterface
{
    private string $ownUserId = '';

    /** @var array<string, array{channel: string, message: string}> askId → the prompt to retire */
    private array $approvalMessages = [];

    public function __construct(
        private readonly string $account,
        private readonly Rest $rest,
        private readonly Gateway $gateway,
        private readonly Bridge $bridge,
        private readonly array $cfg,
        private readonly Log $log,
    ) {
        $gateway->onDispatch(fn (string $type, array $d) => $this->onDispatch($type, $d));
        $gateway->onReady(function (): void {
            $this->ownUserId = (string) ($this->gateway->applicationId ?? $this->ownUserId);
            $this->deployCommands();
        });
    }

    private function boolCfg(string $key, bool $default): bool
    {
        $value = $this->cfg[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }

    private function strCfg(string $key, string $default): string
    {
        $value = $this->cfg[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /** @param list<string> $default */
    private function listCfg(string $key, array $default = []): array
    {
        $value = $this->cfg[$key] ?? null;
        if (!\is_array($value)) {
            return $default;
        }

        return \array_values(\array_filter($value, static fn (mixed $v): bool => \is_string($v) || \is_int($v)));
    }

    /** @return array<string,mixed> */
    private function mapCfg(string $key): array
    {
        $value = $this->cfg[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    public function name(): string
    {
        return 'discord';
    }

    public function textChunkLimit(): int
    {
        return 2000;
    }

    public function maxLinesPerMessage(): int
    {
        return 17;
    }

    public function start(): void
    {
        $this->rest->application()->then(
            function (array $app): void {
                $this->ownUserId = (string) ($app['id'] ?? '');
                $this->log->info('discord_app', ['id' => $this->ownUserId]);
            },
            fn (\Throwable $e) => $this->log->error('discord_app_lookup_failed', ['error' => $e->getMessage()]),
        );
        $this->gateway->start();
    }

    public function stop(): void
    {
        $this->gateway->stop();
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
        $channelId = (string) ($ref['channelId'] ?? '');
        if ($channelId === '') {
            return \React\Promise\reject(new \RuntimeException('discord deliver without a channelId ref'));
        }
        $extra = \array_filter([
            'message_reference' => isset($ref['messageId'])
                ? (object) ['message_id' => (string) $ref['messageId'], 'fail_if_not_exists' => false]
                : null,
        ], static fn (mixed $v): bool => $v !== null);

        return $this->rest->send($channelId, $chunk, $extra + ['allowed_mentions' => ['parse' => []]]);
    }

    public function promptApproval(array $ref, string $sessionId, array $ask): void
    {
        $channelId = (string) ($ref['channelId'] ?? '');
        if ($channelId === '') {
            return;
        }
        $payload = Buttons::approvalPayload($ask, self::approvalSummary($ask));
        $this->rest->send($channelId, (string) $payload['content'], [
            'components' => $payload['components'],
            'flags' => Buttons::FLAG_COMPONENTS_V2,
            'allowed_mentions' => ['parse' => []],
        ])->then(
            function (array $sent) use ($channelId, $ask): void {
                $askId = (string) ($ask['askId'] ?? '');
                if ($askId !== '') {
                    $this->approvalMessages[$askId] = ['channel' => $channelId, 'message' => (string) ($sent['id'] ?? '')];
                }
            },
            fn (\Throwable $e) => $this->log->warn('approval_prompt_failed', ['error' => $e->getMessage()]),
        );
    }

    public function retireApproval(string $askId, string $line): void
    {
        $where = $this->approvalMessages[$askId] ?? null;
        if ($where === null || $where['message'] === '') {
            return;
        }
        unset($this->approvalMessages[$askId]);
        $this->rest->patch("/channels/{$where['channel']}/messages/{$where['message']}", [
            'content' => $line,
            'components' => [],
            'flags' => Buttons::FLAG_COMPONENTS_V2,
        ])->then(null, fn (\Throwable $e) => $this->log->warn('approval_retire_failed', ['error' => $e->getMessage()]));
    }

    public function typing(array $ref): void
    {
        $channelId = (string) ($ref['channelId'] ?? '');
        if ($channelId !== '' && $this->boolCfg('typing', true)) {
            $this->rest->typing($channelId)->then(null, static function (): void {
            });
        }
    }

    // ── inbound ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $d */
    private function onDispatch(string $type, array $d): void
    {
        match ($type) {
            'MESSAGE_CREATE' => $this->onMessage($d),
            'INTERACTION_CREATE' => $this->onInteraction($d),
            default => null,
        };
    }

    /** @param array<string,mixed> $message */
    private function onMessage(array $message): void
    {
        $author = \is_array($message['author'] ?? null) ? $message['author'] : [];
        $authorId = (string) ($author['id'] ?? '');
        $isBot = (bool) ($author['bot'] ?? false);
        if (Keys::ignoreAuthor($authorId !== '' && $authorId === $this->ownUserId, $isBot, $this->boolCfg('allowBots', false))) {
            return;
        }
        if (isset($message['webhook_id']) && $message['webhook_id'] !== null) {
            return; // a webhook/integration post is not a human (§7.3 loop floor)
        }

        $content = (string) ($message['content'] ?? '');
        $channelId = (string) ($message['channel_id'] ?? '');
        if ($channelId === '') {
            return;
        }
        $isDm = !isset($message['guild_id']) || (string) $message['guild_id'] === '';
        $mentions = (array) ($message['mentions'] ?? []);
        $isMention = \in_array($this->ownUserId, \array_map(static fn (mixed $m): string => (string) (\is_array($m) ? $m['id'] ?? '' : ''), $mentions), true)
            || \str_contains((string) ($message['content'] ?? ''), '<@' . $this->ownUserId . '>')
            || \str_contains((string) ($message['content'] ?? ''), '<@!' . $this->ownUserId . '>');

        if (!$this->allowed($isDm, $authorId, (string) ($message['guild_id'] ?? ''), $channelId, $isMention)) {
            return;
        }

        // A leading mention is how a guild slash-thing arrives as text; the
        // mention itself is stripped by Commands::parseText, not here, so a
        // plain "@bot hello" still reads as "hello" after we mark it a mention.
        $text = $content;
        if ($isMention) {
            $text = \trim((string) \preg_replace('/<@!?' . \preg_quote($this->ownUserId, '/') . '>\s*/', '', $content));
        }
        if ($text === '') {
            return;
        }

        $key = $isDm
            ? Keys::dmKey('discord', $this->account, $authorId, $channelId, $this->strCfg('dmScope', Keys::DM_PER_PEER))
            : Keys::channelKey('discord', $this->account, $channelId);
        $ref = ['channelId' => $channelId, 'messageId' => (string) ($message['id'] ?? '')];

        $command = Commands::parseText($content);
        if ($command !== null) {
            $this->bridge->runCommand('discord', $key, $ref, $command);

            return;
        }
        if ($isDm || $isMention || $this->boolCfg('respondWithoutMention', false)) {
            $this->bridge->inbound('discord', $key, $ref, $text, $isMention, (string) ($message['id'] ?? ''));
        }
    }

    /** @param array<string,mixed> $interaction */
    private function onInteraction(array $interaction): void
    {
        $id = (string) ($interaction['id'] ?? '');
        $token = (string) ($interaction['token'] ?? '');
        $kind = (int) ($interaction['type'] ?? 0);
        $member = \is_array($interaction['member'] ?? null) ? $interaction['member'] : (
            \is_array($interaction['user'] ?? null) ? ['user' => $interaction['user']] : []
        );
        $userId = (string) ($member['user']['id'] ?? $interaction['user']['id'] ?? '');
        $userName = (string) ($member['user']['global_name'] ?? $member['user']['username'] ?? $userId);

        if ($kind === Buttons::INTERACTION_TYPE_BUTTON) {
            $parsed = Buttons::parseButton($interaction);
            if ($parsed === null) {
                return;
            }
            $this->bridge->answerByAskId($parsed['askId'], $parsed['verdict'], ['userId' => $userId, 'name' => $userName]);
            // ACK the button so Discord stops the spinner; the message is
            // retired by the permission.resolved event, not here.
            $this->rest->respondInteraction($id, $token, 6, [])->then(null, fn (\Throwable $e) => $this->log->debug('button_ack_failed', ['error' => $e->getMessage()]));

            return;
        }

        if ($kind === Buttons::INTERACTION_TYPE_SLASH) {
            $command = Commands::parseInteraction($interaction);
            if ($command === null) {
                return;
            }
            $channelId = (string) ($interaction['channel_id'] ?? '');
            $key = Keys::channelKey('discord', $this->account, $channelId);
            $ref = ['channelId' => $channelId, 'messageId' => ''];
            $this->rest->respondInteraction($id, $token, Buttons::RESPONSE_TYPE_MESSAGE, [
                'content' => self::ackFor($command['name']),
                'flags' => Buttons::FLAG_EPHEMERAL,
            ])->then(null, fn (\Throwable $e) => $this->log->warn('slash_ack_failed', ['error' => $e->getMessage()]));
            $this->bridge->runCommand('discord', $key, $ref, $command);
        }
    }

    /** @param array{name:string,args:list<string>} $command */
    private static function ackFor(string $name): string
    {
        return match ($name) {
            'new' => 'Opening a new session…',
            'stop' => 'Interrupting the running turn…',
            'approve' => 'Answering the permission ask…',
            'queue' => 'Queued behind the running turn.',
            default => 'Working…',
        };
    }

    /** The §7.4/§9.2 policy door: DMs by dmPolicy, guilds by allowlist + mention rule. */
    private function allowed(bool $isDm, string $authorId, string $guildId, string $channelId, bool $isMention): bool
    {
        if ($isDm) {
            $dmPolicy = $this->strCfg('dmPolicy', 'allowlist');
            if ($dmPolicy === 'open') {
                return true;
            }
            if ($dmPolicy === 'disabled') {
                return false;
            }
            // 'allowlist': a named roster, or the pairing store (P4 adds the
            // code flow; until then an empty roster means NO DM is served —
            // fail closed, not fail open).
            $allow = \array_map('strval', $this->listCfg('allowUsers'));

            return $allow !== [] && \in_array($authorId, $allow, true);
        }
        $guilds = $this->mapCfg('guilds');
        if (!\is_array($guilds) || $guilds === []) {
            return false; // no guild policy configured: the bridge stays out of guilds
        }
        $guild = \is_array($guilds[$guildId] ?? null) ? $guilds[$guildId] : null;
        if ($guild === null) {
            return false;
        }
        $channels = \is_array($guild['allowChannels'] ?? null) ? $guild['allowChannels'] : [];
        if ($channels !== [] && !\in_array($channelId, \array_map('strval', $channels), true)) {
            return false;
        }
        if (($guild['requireMention'] ?? true) === true && !$isMention) {
            return false;
        }

        return true;
    }

    private function deployCommands(): void
    {
        if (!$this->boolCfg('appCommands', true)) {
            $this->log->info('discord_slash_deploy_disabled');

            return;
        }
        $app = $this->gateway->applicationId ?? '';
        if ($app === '') {
            $this->log->warn('discord_slash_deploy_skipped', ['why' => 'no application id yet']);

            return;
        }
        $guilds = $this->mapCfg('guilds');
        $commands = Commands::definitions();
        if (\is_array($guilds) && $guilds !== []) {
            // Guild-scoped deploy is instant; global PUTs cache for an hour.
            foreach (\array_keys($guilds) as $guildId) {
                $this->rest->deployGuildCommands($app, (string) $guildId, $commands)
                    ->then(
                        fn () => $this->log->info('discord_slash_deployed', ['scope' => 'guild', 'guild' => (string) $guildId]),
                        fn (\Throwable $e) => $this->log->warn('discord_slash_deploy_failed', ['guild' => (string) $guildId, 'error' => $e->getMessage()]),
                    );
            }

            return;
        }
        $this->rest->deployGlobalCommands($app, $commands)->then(
            fn () => $this->log->info('discord_slash_deployed', ['scope' => 'global']),
            fn (\Throwable $e) => $this->log->warn('discord_slash_deploy_failed', ['error' => $e->getMessage()]),
        );
    }

    /** @param array<string,mixed> $ask */
    private static function approvalSummary(array $ask): string
    {
        $tool = (string) ($ask['tool'] ?? 'a tool');
        $reason = (string) ($ask['reason'] ?? '');
        $args = $ask['arguments'] ?? [];
        $rendered = \is_array($args) ? (string) \json_encode($args, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE) : (string) $args;
        if (\strlen($rendered) > 400) {
            $rendered = \substr($rendered, 0, 400) . '…';
        }

        return \trim("candy-code is asking to run **{$tool}**.\n{$reason}\n```\n{$rendered}\n```");
    }
}
