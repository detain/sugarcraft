<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Discord;

use SugarCraft\Code\Lang;
use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Bridge\PlatformFace;
use CandyCode\Bundles\Discord\Support\Keys;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\PairingCodes;
use CandyCode\Bundles\Discord\Support\TextSafety;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend\PendingAsk;

/**
 * One Discord account's face: gateway dispatches in, REST out (§7).
 *
 * Inbound discipline, in order: ignore our own author id (bot-loop guard);
 * apply the `allowBots` three-state floor (§7.4 — "mentions" lets a bot
 * through only when it addressed us); drop webhook/integration posts;
 * DMs follow the `dmPolicy` ladder (pairing is §7.3: an unpaired peer gets
 * a pairing code, never the model); guilds follow the `groupPolicy`
 * allowlist with per-guild `requireMention` (the degraded messageContent
 * posture forces mention-only EVERYWHERE, §7.1) and `ignoreOtherMentions`.
 * The §9.1 key is resolved next — a thread's channel id IS the key, which
 * is how "one thread, one session" falls out for free; DMs ride the
 * `dmScope` ladder. Commands ride BOTH spellings: a deployed slash
 * interaction (§7.10, ruling D7=7a) or the `/word` text fallback;
 * everything else is a prompt, with attachments down-selected to ≤4 images
 * + ≤2 PDFs under the size cap (§7.8) and every skip DISCLOSED.
 *
 * Outbound: plain content, `allowed_mentions.parse = []` so model output
 * cannot ping humans, chunked by the Bridge under OUR declared 2000/17
 * (§7.7) before it gets here, `message_reference` keeping replies inside
 * the thread that asked, embeds suppressed when configured (§7.7).
 */
final class DiscordAccount implements PlatformFace
{
    private string $ownUserId = '';

    /** @var array<string, array{channel: string, message: string}> askId → the prompt to retire */
    private array $approvalMessages = [];

    /** @var array<string, TimerInterface> askId → the expiry timer (§7.9 ttlMs posture) */
    private array $approvalTimers = [];

    /** @var array<string, int> thread-ish channel id → last activity stamp (idle sweep, §7.5) */
    private array $threadSeen = [];

    public function __construct(
        private readonly string $account,
        private readonly Rest $rest,
        private readonly Gateway $gateway,
        private ConversationRouter $router,
        private readonly array $cfg,
        private readonly Log $log,
        private readonly PairingCodes $pairing,
        private readonly TextSafety $safety,
        private readonly LoopInterface $loop,
    ) {
        $gateway->onDispatch(fn (string $type, array $d) => $this->onDispatch($type, $d));
        $gateway->onReady(function (): void {
            $this->ownUserId = (string) ($this->gateway->applicationId ?? $this->ownUserId);
            $this->deployCommands();
        });
    }

    // ── config helpers (bridge discipline: wrong type = default, never a crash) ──

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

    /** @return array<string,mixed> */
    private function nested(string $section, string $key, mixed $default): mixed
    {
        $map = $this->mapCfg($section);

        return $map[$key] ?? $default;
    }

    // ── PlatformFace ──

    public function name(): string
    {
        return 'discord';
    }

    public function textChunkLimit(): int
    {
        $limit = $this->nested('outbound', 'textChunkLimit', 2000);

        return \is_int($limit) && $limit >= 100 ? $limit : 2000;
    }

    public function maxLinesPerMessage(): int
    {
        $lines = $this->nested('outbound', 'maxLinesPerMessage', 17);

        return \is_int($lines) && $lines >= 1 ? $lines : 17;
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
        $this->undeployCommands();
        $this->gateway->stop();
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
        $channelId = (string) ($ref['channelId'] ?? '');
        if ($channelId === '') {
            return \React\Promise\reject(new \RuntimeException('discord deliver without a channelId ref'));
        }
        $extra = \array_filter([
            'message_reference' => isset($ref['messageId']) && $ref['messageId'] !== ''
                ? (object) ['message_id' => (string) $ref['messageId'], 'fail_if_not_exists' => false]
                : null,
            'flags' => $this->boolCfg('suppressEmbeds', true) ? Buttons::FLAG_SUPPRESS_EMBEDS : null,
        ], static fn (mixed $v): bool => $v !== null);

        return $this->rest->send($channelId, $chunk, $extra + ['allowed_mentions' => ['parse' => []]]);
    }

    /**
     * §7.9: render-once (the Bridge's presentedAsks gate is upstream), with
     * the approver roster stamped per button (`allowed_users` is the
     * platform-side courtesy filter; the roster check in the Bridge's
     * answerApproval is one wall, the C-1 nonce + conversation binding
     * carried from here is the other), and an expiry timer that RETIRES
     * the prompt when nobody answers — the same disable-on-resolve PATCH,
     * clock-triggered. The window self-clamps to the core expiry ceiling
     * (see approvalTtlSeconds(), co-TTL code-chat §11.6) so the card can
     * never advertise an answer window the core will not honor.
     */
    public function promptApproval(array $ref, string $sessionId, array $ask, string $nonce = ''): void
    {
        $channelId = (string) ($ref['channelId'] ?? '');
        if ($channelId === '') {
            return;
        }
        $roster = \array_map('strval', $this->listCfg('approvalRoster'));
        $payload = Buttons::approvalPayload($ask, self::approvalSummary($ask), $roster, $nonce);
        $this->rest->send($channelId, (string) $payload['content'], [
            'components' => $payload['components'],
            'flags' => Buttons::FLAG_COMPONENTS_V2,
            'allowed_mentions' => ['parse' => []],
        ])->then(
            function (array $sent) use ($channelId, $ask): void {
                $askId = (string) ($ask['askId'] ?? '');
                if ($askId === '') {
                    return;
                }
                $this->approvalMessages[$askId] = ['channel' => $channelId, 'message' => (string) ($sent['id'] ?? '')];
                $this->approvalTimers[$askId] = $this->loop->addTimer(
                    $this->approvalTtlSeconds(),
                    function () use ($askId): void {
                        unset($this->approvalTimers[$askId]);
                        $this->retireApproval($askId, "\u{231B} Approval expired; answer with /approve <askId> once|always|deny while the ask is still open.");
                    },
                );
            },
            fn (\Throwable $e) => $this->log->warn('approval_prompt_failed', ['error' => $e->getMessage()]),
        );
    }

    /**
     * The card-side window of the co-TTL pair (code-chat §11.6): the core
     * refuses the ask at PendingAsk::EXPIRY_SECONDS no matter what this
     * card promises, so the advertised window self-clamps to that ceiling
     * and says so LOUDLY — a longer configured ttlMinutes must never leave
     * a live card sitting over a dead ask. Short windows keep the
     * 60-second floor. Reading the constant (rather than repeating its
     * value) is what makes the docblock's "must move with it" law
     * structural: the card follows the core, silently in the safe
     * direction, noisily in the lying direction.
     */
    private function approvalTtlSeconds(): int
    {
        $minutes = (int) $this->nested('approvals', 'ttlMinutes', 30);
        $seconds = \max(60, $minutes * 60);
        $ceiling = PendingAsk::EXPIRY_SECONDS;
        if ($seconds > $ceiling) {
            $this->log->warn('approval_ttl_clamped', [
                'configuredMinutes' => $minutes,
                'requestedSeconds' => $seconds,
                'armedSeconds' => $ceiling,
            ]);

            return $ceiling;
        }

        return $seconds;
    }

    public function retireApproval(string $askId, string $line): void
    {
        $timer = $this->approvalTimers[$askId] ?? null;
        if ($timer !== null) {
            $this->loop->cancelTimer($timer);
            unset($this->approvalTimers[$askId]);
        }
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

    /** Cancel every armed timer (dispose law — the loop must come back empty). */
    public function cancelAllTimers(): void
    {
        foreach ($this->approvalTimers as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->approvalTimers = [];
    }

    /** The resolved messageContent posture (§7.1) for tests/telemetry. */
    public function degraded(): bool
    {
        return !$this->gateway->messageContentEnabled();
    }

    // ── inbound ──

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
        $isOwn = $authorId !== '' && $authorId === $this->ownUserId;

        $content = (string) ($message['content'] ?? '');
        $channelId = (string) ($message['channel_id'] ?? '');
        if ($channelId === '') {
            return;
        }
        $isDm = !isset($message['guild_id']) || (string) $message['guild_id'] === '';
        $mentions = \is_array($message['mentions'] ?? []) ? $message['mentions'] : [];
        $isMention = ($this->ownUserId !== '' && \in_array($this->ownUserId, \array_map(static fn (mixed $m): string => (string) (\is_array($m) ? $m['id'] ?? '' : ''), $mentions), true))
            || ($this->ownUserId !== '' && (\str_contains($content, '<@' . $this->ownUserId . '>') || \str_contains($content, '<@!' . $this->ownUserId . '>')));

        // Bot-loop floor (§7.4), three states: own messages ALWAYS ignored;
        // 'true' admits bots; 'false' refuses; 'mentions' admits a bot only
        // when it addressed us.
        $botsMode = $this->strCfg('allowBots', 'mentions');
        $allowBots = $botsMode === 'true' || ($botsMode === 'mentions' && $isMention);
        if (Keys::ignoreAuthor($isOwn, $isBot, $allowBots)) {
            return;
        }
        if (isset($message['webhook_id'])) {
            return; // a webhook/integration post is not a human (§7.3 loop floor)
        }

        // §7.1 degraded posture: with the messageContent intent DECLINED or
        // DISCOVERED-refused, mention-only is forced everywhere, no override.
        $degraded = !$this->gateway->messageContentEnabled();

        if ($isDm) {
            if (!$this->handleDmPolicy($authorId, $channelId, $content)) {
                return;
            }
        } elseif (!$this->allowedGuild((string) ($message['guild_id'] ?? ''), $channelId, $isMention, $mentions, $degraded)) {
            return;
        }

        // The own-mention is stripped; OTHER mention tokens are dropped so
        // the transcript carries no addressing noise (§7.4).
        $text = $content;
        if ($this->ownUserId !== '') {
            $text = (string) \preg_replace('/<@!?' . \preg_quote($this->ownUserId, '/') . '>\s*/', '', $text);
        }
        $text = \trim((string) \preg_replace('/<@!?\d+>/', '', $text));

        $key = $isDm
            ? Keys::dmKey('discord', $this->account, $authorId, $channelId, $this->strCfg('dmScope', Keys::DM_PER_PEER))
            : Keys::channelKey('discord', $this->account, $channelId);
        $inboundId = (string) ($message['id'] ?? '');
        $ref = ['channelId' => $channelId, 'messageId' => $inboundId];
        if (!$isDm) {
            // §7.5 idle sweep material: every served guild conversation gets
            // an activity stamp; archiveThread then proves thread-ness over
            // REST before touching it, so a regular channel is never archived.
            $this->threadSeen[$channelId] = time();
        }

        // A command is text-only by definition — media posted beside a
        // slash-word is disclosed as skipped, never silently fetched.
        $command = Commands::parseText($content);
        if ($command !== null) {
            // A RESUME replays MESSAGE_CREATE and a command is not idempotent
            // (/new mints a session). Prompts are deduped by the router's inbound().
            if ($inboundId !== '' && !$this->router->claimInbound('discord', $inboundId)) {
                $this->log->debug('discord_command_duplicate');

                return;
            }
            if ($command['name'] === 'pair') {
                $this->handlePairCommand($ref, (array) $command['args'], $authorId, $isDm, null);

                return;
            }
            $this->router->runCommand('discord', $key, $ref + ['__actor' => ['userId' => $authorId, 'name' => (string) ($author['global_name'] ?? $author['username'] ?? $authorId)]], $command);

            return;
        }

        $rawAttachments = \is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        if ($text === '' && $rawAttachments === []) {
            return;
        }

        // The door said this conversation is served; the floor says whether
        // THIS message is a prompt for us at all.
        if (!($isDm || $isMention || $this->boolCfg('respondWithoutMention', false))) {
            return;
        }

        if ($rawAttachments === []) {
            $this->router->inbound('discord', $key, $ref, $text, $isMention, $inboundId);

            return;
        }
        // §7.8: media rides the settled batch — nothing is fetched before
        // the policy doors have said yes, and the ONE inbound carries
        // whatever the down-select kept.
        $this->collectMediaAndDispatch($rawAttachments, $key, $ref, $text, $isMention, $inboundId);
    }

    /**
     * The §7.3 DM ladder. Returns true when the message may proceed.
     * Pairing is a GATE, not a prompt: an unpaired peer gets its code
     * notice (one per reminder window) and never reaches the model.
     */
    private function handleDmPolicy(string $authorId, string $channelId, string $content): bool
    {
        $dmPolicy = $this->strCfg('dmPolicy', 'pairing');
        if ($dmPolicy === 'open') {
            return true;
        }
        if ($dmPolicy === 'disabled') {
            return false;
        }
        if ($dmPolicy !== 'pairing') {
            // 'allowlist': the pairing store's approved roster ∪ settings names.
            $allow = \array_map('strval', \array_merge($this->pairing->allowUsers($this->account), $this->listCfg('allowFrom')));

            return $allow !== [] && \in_array($authorId, $allow, true);
        }
        if ($this->pairing->isPaired($this->account, $authorId)) {
            return true;
        }
        if (\trim($content) === '') {
            return false; // an empty unpaired DM gets nothing to answer with
        }
        $outcome = $this->pairing->request($this->account, $authorId);
        $notice = match ($outcome['kind']) {
            'new', 'pending' => Lang::t('channels.discord.pairing.heading') . "\n"
                . Lang::t('channels.discord.pairing.code_line', ['code' => (string) ($outcome['code'] ?? '')]) . "\n"
                . Lang::t('channels.discord.pairing.approve_hint', ['code' => (string) ($outcome['code'] ?? '')]),
            'full' => Lang::t('channels.discord.pairing.full'),
            default => '', // 'silent' / 'paired': inside the reminder window, or raced onto the roster
        };
        if ($notice !== '') {
            // The notice IS the code's one legitimate surface (§7.3): it
            // rides raw REST, NOT safety->outbound — everywhere else
            // (transcripts, logs, channel chatter) the code is redacted,
            // because PairingCodes registered it as a secret.
            $this->rest->send($channelId, $notice, ['allowed_mentions' => ['parse' => []]])
                ->then(null, fn (\Throwable $e) => $this->log->warn('pairing_notice_failed', ['error' => $e->getMessage()]));
            $this->log->info('pairing_requested', ['account' => $this->account, 'kind' => $outcome['kind']]);
        }

        return false;
    }

    /**
     * §7.4 guild door: allowlist default — a guild is served only when
     * `groupPolicy` says open OR the guild has a policy row (an empty
     * `allowChannels` = every channel in that guild). `requireMention`
     * defaults true per guild; the degraded floor (§7.1) forces mention-
     * only everywhere. With `ignoreOtherMentions` (the default), a post
     * that addresses somebody else is not for us.
     *
     * @param list<mixed> $mentions
     */
    private function allowedGuild(string $guildId, string $channelId, bool $isMention, array $mentions, bool $degraded): bool
    {
        $guilds = $this->mapCfg('guilds');
        $guild = \is_array($guilds[$guildId] ?? null) ? $guilds[$guildId] : null;
        $groupPolicy = $this->strCfg('groupPolicy', 'allowlist');
        if ($guild === null && $groupPolicy !== 'open') {
            return false;
        }
        if ($guild !== null && (string) ($guild['policy'] ?? 'allowlist') === 'disabled') {
            return false;
        }
        $channels = \is_array($guild['allowChannels'] ?? null) ? $guild['allowChannels'] : [];
        if ($channels !== [] && !\in_array($channelId, \array_map('strval', $channels), true)) {
            return false;
        }
        if ($degraded) {
            return $isMention;
        }
        $requireMention = $guild === null ? true : (bool) ($guild['requireMention'] ?? true);
        if ($requireMention && !$isMention) {
            return false;
        }
        if ($this->boolCfg('ignoreOtherMentions', true)) {
            foreach ($mentions as $mention) {
                $id = (string) (\is_array($mention) ? $mention['id'] ?? '' : '');
                if ($id !== '' && $id !== $this->ownUserId) {
                    return false; // addressed to someone else
                }
            }
        }

        return true;
    }

    /**
     * §7.8 down-select: ≤4 images + ≤2 PDFs, each declared AND actually
     * within `media.maxMb`; anything else is skipped with a disclosed,
     * names-only notice. Fetches run concurrently; the settled batch
     * becomes ONE prompt. Wire shape per entry:
     * {name, mime, data: strict-base64} (§9 media contract).
     *
     * @param list<mixed> $raw
     */
    private function collectMediaAndDispatch(array $raw, string $key, array $ref, string $text, bool $isMention, string $inboundId): void
    {
        $maxMb = (int) $this->nested('media', 'maxMb', 100);
        $maxBytes = \max(1, $maxMb) * 1048576;
        $caps = [
            'images' => (int) $this->nested('media', 'images', 4),
            'documents' => (int) $this->nested('media', 'documents', 2),
        ];

        $notes = [];
        $jobs = [];
        $accepted = ['images' => 0, 'documents' => 0];
        foreach ($raw as $attachment) {
            if (!\is_array($attachment)) {
                continue;
            }
            $name = (string) ($attachment['filename'] ?? 'attachment');
            $mime = (string) ($attachment['content_type'] ?? '');
            $url = (string) ($attachment['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $slot = \str_starts_with($mime, 'image/') ? 'images' : ($mime === 'application/pdf' ? 'documents' : '');
            if ($slot === '') {
                $notes[] = "{$name} (unsupported type)";

                continue;
            }
            if ($accepted[$slot] >= $caps[$slot]) {
                $notes[] = "{$name} (past the " . ($slot === 'images' ? 'image' : 'document') . ' limit of ' . $caps[$slot] . ')';

                continue;
            }
            if ((int) ($attachment['size'] ?? 0) > $maxBytes) {
                $notes[] = "{$name} (over the {$maxMb} MB cap)";

                continue;
            }
            // Declared size gets a second chance after the download: the
            // ACTUAL bytes are the truth (§7.8 caps bind the payload).
            ++$accepted[$slot];
            $jobs[] = ['slot' => $slot, 'name' => $name, 'mime' => $mime, 'url' => $url];
        }

        if ($jobs === []) {
            $this->finishMedia($key, $ref, $text, $isMention, $inboundId, [], [], $notes);

            return;
        }
        $promises = [];
        foreach ($jobs as $job) {
            $promises[] = $this->rest->fetchBinary($job['url'])->then(
                static fn (string $bytes): array => ['bytes' => $bytes],
                static fn (\Throwable $e): array => ['error' => $e->getMessage()],
            );
        }
        \React\Promise\all($promises)->then(function (array $settled) use ($key, $ref, $text, $isMention, $inboundId, $jobs, $notes, $maxBytes): void {
            $images = [];
            $documents = [];
            foreach ($settled as $i => $result) {
                $job = $jobs[(int) $i];
                if (!\is_array($result) || isset($result['error'])) {
                    $notes[] = "{$job['name']} (download failed)";

                    continue;
                }
                $bytes = (string) $result['bytes'];
                if (\strlen($bytes) > $maxBytes) {
                    $notes[] = "{$job['name']} (over the size cap after download)";

                    continue;
                }
                $entry = ['name' => $job['name'], 'mime' => $job['mime'], 'data' => \base64_encode($bytes)];
                if ($job['slot'] === 'images') {
                    $images[] = $entry;
                } else {
                    $documents[] = $entry;
                }
            }
            $this->finishMedia($key, $ref, $text, $isMention, $inboundId, $images, $documents, $notes);
        });
    }

    /**
     * One settled batch = one prompt. A caption-less post still asks
     * something of the model, so it carries a disclosed placeholder; every
     * skip rides a separate, names-only notice (§7.8).
     *
     * @param list<array<string,string>> $images
     * @param list<array<string,string>> $documents
     * @param list<string> $notes
     */
    private function finishMedia(string $key, array $ref, string $text, bool $isMention, string $inboundId, array $images, array $documents, array $notes): void
    {
        $caption = $text !== '' ? $text : '(no caption)';
        $this->router->inbound('discord', $key, $ref, $caption, $isMention, $inboundId, $images, $documents);
        if ($notes === []) {
            return;
        }
        $channelId = (string) ($ref['channelId'] ?? '');
        if ($channelId === '') {
            return;
        }
        $notice = Lang::t('channels.discord.attachments.skipped', ['count' => (string) \count($notes), 'notes' => \implode('; ', $notes)]);
        $this->rest->send($channelId, $notice, ['allowed_mentions' => ['parse' => []]])
            ->then(null, fn (\Throwable $e) => $this->log->debug('drop_notice_failed', ['error' => $e->getMessage()]));
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
        $isDm = !isset($interaction['guild_id']) || (string) $interaction['guild_id'] === '';
        $channelId = (string) ($interaction['channel_id'] ?? '');
        $guildId = (string) ($interaction['guild_id'] ?? '');
        $key = $isDm
            ? Keys::dmKey('discord', $this->account, $userId, $channelId, $this->strCfg('dmScope', Keys::DM_PER_PEER))
            : Keys::channelKey('discord', $this->account, $channelId);

        if ($kind === Buttons::INTERACTION_TYPE_BUTTON) {
            $parsed = Buttons::parseButton($interaction);
            if ($parsed === null) {
                return;
            }
            // C-1: a button IS an approval answer — the clicker must pass
            // the same access floor the message path applies, before the
            // answer (nonce + binding, enforced by the router) goes near
            // the permission wire. Refusal is ephemeral and loud.
            if (!$this->interactionActorAllowed($isDm, $userId, $guildId, $channelId)) {
                $this->log->warn('approval_button_refused', ['ask' => $parsed['askId'], 'user' => $userId]);
                $this->rest->respondInteraction($id, $token, Buttons::RESPONSE_TYPE_MESSAGE, [
                    'content' => Lang::t('channels.discord.approval.not_permitted'),
                    'flags' => Buttons::FLAG_EPHEMERAL,
                ])->then(null, fn (\Throwable $e) => $this->log->debug('button_refuse_ack_failed', ['error' => $e->getMessage()]));

                return;
            }
            $this->router->answerByAskId($parsed['askId'], $parsed['verdict'], ['userId' => $userId, 'name' => $userName], $parsed['nonce'], $key, 'button');
            // ACK the button so Discord stops the spinner; the message is
            // retired by the permission.resolved event, not here.
            $this->rest->respondInteraction($id, $token, 6, [])->then(null, fn (\Throwable $e) => $this->log->debug('button_ack_failed', ['error' => $e->getMessage()]));

            return;
        }

        if ($kind !== Buttons::INTERACTION_TYPE_SLASH) {
            return;
        }
        $command = Commands::parseInteraction($interaction);
        if ($command === null) {
            return;
        }
        $answer = fn (string $text): PromiseInterface => $this->rest->respondInteraction(
            $id,
            $token,
            Buttons::RESPONSE_TYPE_MESSAGE,
            ['content' => $text, 'flags' => $this->boolCfg('slashEphemeral', true) ? Buttons::FLAG_EPHEMERAL : 0],
        );

        // C-1 remainder (F-C1SLASH): EVERY slash verb takes the message-path
        // floor — /new mints sessions, /stop cancels turns, /model flips
        // models, /pair answers the pairing surface, /approve answers a
        // permission ask; an actor who cannot reach the text spelling must
        // not reach any of them through an interaction either. The approve
        // deny keeps its exact string (pinned); the rest refuse generically.
        if (!$this->interactionActorAllowed($isDm, $userId, $guildId, $channelId)) {
            $this->log->warn('slash_refused', ['command' => $command['name'], 'user' => $userId]);
            $answer($command['name'] === 'approve'
                ? Lang::t('channels.discord.approval.not_permitted')
                : Lang::t('channels.discord.command.not_permitted'),
            )->then(null, fn (\Throwable $e) => $this->log->debug('slash_refuse_ack_failed', ['error' => $e->getMessage()]));

            return;
        }

        if ($command['name'] === 'pair') {
            // §7.3: the pairing surface is account-local — codes answer
            // ephemerally and never ride the model or the transcript.
            $this->handlePairCommand(['channelId' => $channelId, 'messageId' => ''], (array) $command['args'], $userId, $isDm, $answer);

            return;
        }

        $ref = ['channelId' => $channelId, 'messageId' => ''];
        $answer(self::ackFor($command['name']))->then(null, fn (\Throwable $e) => $this->log->warn('slash_ack_failed', ['error' => $e->getMessage()]));
        $this->router->runCommand('discord', $key, $ref + ['__actor' => ['userId' => $userId, 'name' => $userName]], $command);
    }

    /**
     * C-1 access floor for gated interactions: every button click and
     * every slash verb (F-C1 + the F-C1SLASH remainder): a targeted
     * interaction has no mention semantics, so
     * the message path's mention/degraded arms do not apply — what applies
     * is the whole dmPolicy ladder (pairing WITHOUT the code notice: this
     * is not a DM asking to be served) and the guild policy row with its
     * channel allowlist. Fail closed: unknown actor, unknown guild,
     * disabled policy all refuse.
     */
    private function interactionActorAllowed(bool $isDm, string $userId, string $guildId, string $channelId): bool
    {
        if ($userId === '') {
            return false;
        }
        if ($isDm) {
            $dmPolicy = $this->strCfg('dmPolicy', 'pairing');
            if ($dmPolicy === 'open') {
                return true;
            }
            if ($dmPolicy === 'disabled') {
                return false;
            }
            if ($dmPolicy !== 'pairing') {
                $allow = \array_map('strval', \array_merge($this->pairing->allowUsers($this->account), $this->listCfg('allowFrom')));

                return $allow !== [] && \in_array($userId, $allow, true);
            }

            return $this->pairing->isPaired($this->account, $userId);
        }
        $guilds = $this->mapCfg('guilds');
        $guild = \is_array($guilds[$guildId] ?? null) ? $guilds[$guildId] : null;
        if ($guild === null && $this->strCfg('groupPolicy', 'allowlist') !== 'open') {
            return false;
        }
        if ($guild !== null && (string) ($guild['policy'] ?? 'allowlist') === 'disabled') {
            return false;
        }
        $channels = \is_array($guild['allowChannels'] ?? null) ? $guild['allowChannels'] : [];

        return $channels === [] || \in_array($channelId, \array_map('strval', $channels), true);
    }

    /** Public door for the router's /pair text fallback (§7.3). */
    public function pairCommand(array $ref, array $args, string $actorId, bool $isDm): void
    {
        $this->handlePairCommand($ref, $args, $actorId, $isDm, null);
    }

    /**
     * §7.3 approval surface: `/pair approve <code>` and `/pair list`.
     * Pairing is an approver's act (mirrors candy-msteams): the roster that
     * answers tool asks answers pairings too, and an EMPTY roster answers
     * nobody — the gate that admits a peer must not default open. Codes are
     * listed only in a direct message. The slash path answers through $reply
     * (ephemeral); the text spelling answers in-channel — the REDACTION law
     * (§9.5) still holds: the confirmation never repeats a code the notice
     * channel doesn't already own, and `approve` consumes the code as it fires.
     *
     * @param list<mixed>                             $args
     * @param null|\Closure(string):PromiseInterface $reply
     */
    private function handlePairCommand(array $ref, array $args, string $actorId, bool $isDm, ?\Closure $reply): void
    {
        $channelId = (string) ($ref['channelId'] ?? '');
        $say = function (string $text) use ($reply, $channelId): void {
            if ($reply !== null) {
                $reply($text);

                return;
            }
            if ($channelId !== '') {
                $this->rest->send($channelId, $text, ['allowed_mentions' => ['parse' => []]])
                    ->then(null, fn (\Throwable $e) => $this->log->warn('pair_answer_failed', ['error' => $e->getMessage()]));
            }
        };

        if (!$this->isApprover($actorId)) {
            $say(Lang::t('channels.discord.pair.only_approver'));

            return;
        }

        $action = \strtolower(\trim((string) ($args[0] ?? 'list')));
        if ($action === 'approve') {
            $code = \strtoupper(\trim((string) ($args[1] ?? '')));
            $paired = $code === '' ? null : $this->pairing->approve($this->account, $code);
            $say($paired === null ? Lang::t('channels.discord.pair.no_match') : Lang::t('channels.discord.pair.paired', ['paired' => (string) $paired]));

            return;
        }

        if (!$isDm) {
            $say(Lang::t('channels.discord.pair.use_dm'));

            return;
        }
        $rows = $this->pairing->pending($this->account);
        $say($rows === []
            ? Lang::t('channels.discord.pair.none_pending')
            : \implode("\n", \array_map(
                static fn (array $row): string => Lang::t('channels.discord.pair.row', ['code' => (string) $row['code'], 'userId' => (string) $row['userId']]),
                $rows,
            )));
    }

    /** The approver roster (the list that answers tool asks). An empty roster answers nobody. */
    private function isApprover(string $actorId): bool
    {
        $roster = \array_map('strval', $this->listCfg('approvalRoster'));

        return $actorId !== '' && $roster !== [] && \in_array($actorId, $roster, true);
    }

    private static function ackFor(string $name): string
    {
        return match ($name) {
            'new' => Lang::t('channels.discord.ack.new'),
            'stop' => Lang::t('channels.discord.ack.stop'),
            'approve' => Lang::t('channels.discord.ack.approve'),
            'queue' => Lang::t('channels.discord.ack.queue'),
            'model' => Lang::t('channels.discord.ack.model'),
            default => Lang::t('channels.discord.ack.default'),
        };
    }

    /** §7.10 (ruling D7=7a): deploy the slash roster on lifecycle. */
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
        if ($guilds !== []) {
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

    /**
     * §7.10 (ruling D7=7a, N5): disabling or removing a face walks back the
     * deploy — every slash command the face pushed is deleted again, scoped
     * exactly as the deploy was (listed guilds => per-guild, otherwise
     * global). This is a courtesy on the way out: it never throws, announces
     * a failure at most once however many legs fell over, and if the loop
     * is already down the deletes simply do not land. Teardown must never
     * block behind a dead API.
     */
    private function undeployCommands(): void
    {
        if (!$this->boolCfg('appCommands', true)) {
            // A face that never deployed has nothing to retract — silently.
            return;
        }
        $app = $this->gateway->applicationId ?? '';
        if ($app === '') {
            return;
        }
        $noticed = false;
        $notice = function (\Throwable $e) use (&$noticed): void {
            if ($noticed) {
                return;
            }
            $noticed = true;
            $this->log->warn('discord_slash_undeploy_failed', ['error' => $e->getMessage()]);
        };
        $paths = [];
        foreach (\array_keys($this->mapCfg('guilds')) as $guildId) {
            $paths[] = '/applications/' . $app . '/guilds/' . $guildId . '/commands';
        }
        if ($paths === []) {
            $paths[] = '/applications/' . $app . '/commands';
        }
        foreach ($paths as $path) {
            $this->rest->get($path)->then(
                function (array $rows) use ($path, $notice): void {
                    foreach ($rows as $row) {
                        $id = (string) (\is_array($row) ? ($row['id'] ?? '') : '');
                        if ($id === '') {
                            continue;
                        }
                        $this->rest->delete($path . '/' . $id)->then(null, $notice);
                    }
                    $this->log->info('discord_slash_undeployed', ['path' => $path]);
                },
                $notice,
            );
        }
    }

    /**
     * The serve-dial state machine rebuilds the router whenever the local
     * serve endpoint (re)appears; the faces outlive the dial, so they take
     * the new router here instead of being re-constructed.
     */
    public function rebindRouter(ConversationRouter $router): void
    {
        $this->router = $router;
    }

    public function accountName(): string
    {
        return $this->account;
    }

    /**
     * Guild conversations whose last activity predates the limit. Reported,
     * not pruned — the stamp survives until archiveThread confirms otherwise.
     *
     * @return list<string>
     */
    public function idleThreads(int $olderThan): array
    {
        $idle = [];
        foreach ($this->threadSeen as $channelId => $stamp) {
            if ($stamp < $olderThan) {
                $idle[] = (string) $channelId;
            }
        }

        return $idle;
    }

    /**
     * §7.5 idle-hours archive. MESSAGE_CREATE carries no channel TYPE, so
     * thread-ness is proven with one GET before the PATCH: channels 10/11/12
     * (public/announcement/private thread) get archived, a regular channel
     * just loses its stamp. The PATCH shape follows Discord's
     * channel-modify docs and is disclosed offline-unverified in the report.
     */
    public function archiveThread(string $channelId): void
    {
        $this->rest->get('/channels/' . $channelId)->then(
            function (array $channel) use ($channelId): void {
                $type = (int) ($channel['type'] ?? -1);
                if ($type !== 10 && $type !== 11 && $type !== 12) {
                    unset($this->threadSeen[$channelId]);

                    return;
                }
                $this->rest->patch('/channels/' . $channelId, ['archived' => true])->then(
                    function () use ($channelId): void {
                        unset($this->threadSeen[$channelId]);
                        $this->log->info('discord_thread_archived', ['account' => $this->account, 'channel' => $channelId]);
                    },
                    function (\Throwable $e) use ($channelId): void {
                        // Keep the stamp: the next sweep retries. Loud in the
                        // log, quiet in the channel — housekeeping never spams users.
                        $this->log->warn('discord_thread_archive_failed', ['channel' => $channelId, 'error' => $e->getMessage()]);
                    },
                );
            },
            function (\Throwable $e) use ($channelId): void {
                unset($this->threadSeen[$channelId]);
                $this->log->debug('discord_thread_probe_failed', ['channel' => $channelId, 'error' => $e->getMessage()]);
            },
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
