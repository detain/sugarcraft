<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

use CandyCode\Bundles\MsTeams\Bridge\ConversationRouter;
use CandyCode\Bundles\MsTeams\Bridge\PlatformFace;
use CandyCode\Bundles\MsTeams\Support\Keys;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\PairingCodes;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

/**
 * One Teams account's face: webhook activities in, serviceUrl REST out
 * (§8). The inverse transport — no dial to Microsoft, no keepalive: the
 * daemon's mounted route delivers verified POSTs and everything the bot
 * says is a reply to a stored conversation reference (§8.6).
 *
 * Inbound discipline, in order (mirroring the shared bridge law): ignore
 * our own author id and foreign bots per `allowBots`; personal chats
 * follow the `dmPolicy` ladder (pairing is the §7.3 SHARED law — same
 * code, same alphabet, same caps); groupChat / channel / teamChannel
 * follow the `groupPolicy` allowlist with GROUPS BLOCKED BY DEFAULT (§8.3:
 * a conversation is served only when named by `groups` or `groupPolicy`
 * says open), `requireMention` default true per row, and the declared
 * degraded posture (no ChannelMessage.Read.Group RSC → mention-only,
 * §8.3); `ignoreOtherMentions` drops posts addressed to somebody else.
 * The §9.1 key resolves next — personal chats ride the `dmScope` ladder,
 * every group conversation keys by its Teams conversation id. Commands
 * are the text roster (Teams slash deploy is messaging-extension
 * territory, P3.5 — §8.7); everything else is a prompt, with attachment
 * down-select kept SMALL in v1: platform-hosted media only, Graph-hosted
 * content refused with a disclosed note (file consent is P3.5's first
 * citizen, §8.7).
 *
 * Outbound: markdown-subset messages via the activity conversation
 * reference, `replyToId` threading replies, retirement of answered cards
 * by activity REPLACE — Teams updates the original message in place, the
 * closest analogue to Discord's components-v2 edit, and the same second-
 * click impossibility law: a retired card carries no actions at all.
 */
final class MsTeamsAccount implements PlatformFace
{
    private const BOT_ID_PREFIXES = ['28:', 'app:'];

    /** @var array<string, array{serviceUrl: string, conversationId: string, activityId: string}> askId → the card to retire */
    private array $approvalMessages = [];

    /** @var array<string, TimerInterface> askId → the expiry timer (§9.6 30-min posture) */
    private array $approvalTimers = [];

    public function __construct(
        private readonly string $account,
        private readonly string $appId,
        private readonly Rest $rest,
        private ConversationRouter $router,
        private readonly array $cfg,
        private readonly Log $log,
        private readonly PairingCodes $pairing,
        private readonly TextSafety $safety,
        private readonly LoopInterface $loop,
    ) {
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
        return 'msteams';
    }

    public function textChunkLimit(): int
    {
        $limit = $this->nested('outbound', 'textChunkLimit', 3800);

        return \is_int($limit) && $limit >= 100 ? $limit : 3800;
    }

    public function maxLinesPerMessage(): int
    {
        $lines = $this->nested('outbound', 'maxLinesPerMessage', 40);

        return \is_int($lines) && $lines >= 1 ? $lines : 40;
    }

    /** No platform dial to start — the webhook IS the connection (§8.1). */
    public function start(): void
    {
        $this->log->info('msteams_face_ready', ['account' => $this->account]);
    }

    public function stop(): void
    {
        // Nothing to disconnect; timers go through cancelAllTimers().
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
        $conversationId = (string) ($ref['conversationId'] ?? '');
        $serviceUrl = (string) ($ref['serviceUrl'] ?? '');
        if ($conversationId === '' || $serviceUrl === '') {
            return \React\Promise\reject(new \RuntimeException('msteams deliver without a conversationReference ref'));
        }
        $activity = [
            'type' => 'message',
            'from' => ['id' => $this->appId],
            'textFormat' => 'markdown',
            'text' => $chunk,
        ];
        $replyTo = (string) ($ref['activityId'] ?? '');
        if ($replyTo !== '') {
            $activity['replyToId'] = $replyTo;
        }

        return $this->rest->sendActivity($serviceUrl, $conversationId, $activity);
    }

    /**
     * §9.6: the adaptive card carries the ask, the summary, (display only)
     * the approver roster, and the C-1 per-ask nonce in every action data —
     * the WALL is the router's answerApproval (roster + nonce + conversation
     * binding), re-checked on the invoke path below behind the account's
     * access floor. The 30-minute posture arms an
     * expiry timer that RETIRES the card clock-triggered, and the same
     * retirement runs when an answer lands: card replace with no actions
     * at all. The universal same-chat `/approve` fallback needs no card —
     * it is parsed by the pipeline before the model, always.
     */
    public function promptApproval(array $ref, string $sessionId, array $ask, string $nonce = ''): void
    {
        $conversationId = (string) ($ref['conversationId'] ?? '');
        $serviceUrl = (string) ($ref['serviceUrl'] ?? '');
        if ($conversationId === '' || $serviceUrl === '') {
            return;
        }
        $roster = \array_map('strval', $this->listCfg('approvalRoster'));
        $activity = Cards::cardActivity($this->appId, Cards::approvalCard($ask, self::approvalSummary($ask), $roster, $nonce));
        $this->rest->sendActivity($serviceUrl, $conversationId, $activity)->then(
            function (array $sent) use ($ask, $serviceUrl, $conversationId): void {
                $askId = (string) ($ask['askId'] ?? '');
                if ($askId === '') {
                    return;
                }
                $this->approvalMessages[$askId] = [
                    'serviceUrl' => $serviceUrl,
                    'conversationId' => $conversationId,
                    'activityId' => (string) ($sent['id'] ?? ''),
                ];
                $minutes = (int) $this->nested('approvals', 'ttlMinutes', 30);
                $this->approvalTimers[$askId] = $this->loop->addTimer(
                    \max(60, $minutes * 60),
                    function () use ($askId): void {
                        unset($this->approvalTimers[$askId]);
                        $this->retireApproval($askId, "\u{231B} Approval expired; answer with /approve <askId> once|always|deny while the ask is still open.");
                    },
                );
            },
            fn (\Throwable $e) => $this->log->warn('approval_prompt_failed', ['error' => $e->getMessage()]),
        );
    }

    public function retireApproval(string $askId, string $line): void
    {
        $timer = $this->approvalTimers[$askId] ?? null;
        if ($timer !== null) {
            $this->loop->cancelTimer($timer);
            unset($this->approvalTimers[$askId]);
        }
        $where = $this->approvalMessages[$askId] ?? null;
        if ($where === null || $where['activityId'] === '') {
            return;
        }
        unset($this->approvalMessages[$askId]);
        $this->rest->updateActivity(
            $where['serviceUrl'],
            $where['conversationId'],
            $where['activityId'],
            Cards::cardActivity($this->appId, Cards::retiredCard($line)),
        )->then(null, fn (\Throwable $e) => $this->log->warn('approval_retire_failed', ['error' => $e->getMessage()]));
    }

    /** Teams typing indicators expire on the platform side (~3 s); one POST is the whole leg. */
    public function typing(array $ref): void
    {
        $conversationId = (string) ($ref['conversationId'] ?? '');
        $serviceUrl = (string) ($ref['serviceUrl'] ?? '');
        if ($conversationId !== '' && $serviceUrl !== '' && $this->boolCfg('typing', true)) {
            $this->rest->sendActivity($serviceUrl, $conversationId, [
                'type' => 'typing',
                'from' => ['id' => $this->appId],
            ])->then(null, static function (): void {
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

    /** The declared RSC posture (§8.3): degraded = the app package lacks ChannelMessage.Read.Group. */
    public function degraded(): bool
    {
        return $this->boolCfg('degraded', false);
    }

    // ── inbound ──

    /**
     * The verified-activity door (the caller JWT-validated the bearer —
     * nothing reaches here unsigned, §8.1).
     *
     * @param array<string,mixed> $activity
     */
    public function handleActivity(array $activity): void
    {
        $type = (string) ($activity['type'] ?? '');
        if ($type === 'invoke') {
            $this->handleInvoke($activity);

            return;
        }
        if ($type !== 'message') {
            return; // conversationUpdate, typing from humans, messageReaction… — not input
        }

        $from = \is_array($activity['from'] ?? null) ? $activity['from'] : [];
        $authorId = (string) ($from['aadObjectId'] ?? $from['id'] ?? '');
        $isOwn = $this->isOurAuthor((string) ($from['id'] ?? ''));
        $isBot = (string) ($from['role'] ?? '') === 'bot' || $this->hasBotPrefix((string) ($from['id'] ?? ''));

        $content = (string) ($activity['text'] ?? '');
        $conversation = \is_array($activity['conversation'] ?? null) ? $activity['conversation'] : [];
        $conversationId = (string) ($conversation['id'] ?? '');
        $serviceUrl = trim((string) ($activity['serviceUrl'] ?? ''));
        if ($conversationId === '' || $serviceUrl === '') {
            return;
        }
        $conversationType = (string) ($conversation['conversationType'] ?? (string) ($activity['conversationType'] ?? 'personal'));
        $isDm = $conversationType === 'personal';
        // Remember every mention entity id for the ignoreOtherMentions door.
        $peers = [];
        foreach ((\is_array($activity['entities'] ?? null) ? $activity['entities'] : []) as $entity) {
            if (\is_array($entity) && (string) ($entity['type'] ?? '') === 'mention') {
                $peers[] = (string) ($entity['mentioned']['user']['id'] ?? '');
            }
        }
        $this->pendingEntityPeers = $peers;
        $isMention = $this->mentionsUs($activity);

        // Bot-loop floor (§7.4): own posts ALWAYS ignored; 'true' admits
        // bots; 'false' refuses; 'mentions' admits a bot only when it
        // addressed us.
        $botsMode = $this->strCfg('allowBots', 'mentions');
        $allowBots = $botsMode === 'true' || ($botsMode === 'mentions' && $isMention);
        if (Keys::ignoreAuthor($isOwn, $isBot, $allowBots)) {
            return;
        }

        // §8.3 degraded posture: the operator declares the RSC was declined
        // (or never granted); mention-only is then forced, no override.
        $degraded = $this->degraded();

        if ($isDm) {
            if (!$this->handleDmPolicy($authorId, $serviceUrl, $conversationId, $content)) {
                return;
            }
        } elseif (!$this->allowedGroup($conversationId, $isMention, $degraded)) {
            return;
        }

        // The own-mention markup is stripped; other <at> tokens are dropped
        // so the transcript carries no addressing noise (§7.4 analogue).
        $text = $content;
        $text = (string) \preg_replace('/<at>\s*' . \preg_quote($this->botName(), '/') . '\s*<\/at>\s*/iu', '', $text);
        $text = \trim((string) \preg_replace('/<at>.*?<\/at>/is', '', $text));

        $key = $isDm
            ? Keys::dmKey('msteams', $this->account, $authorId, $conversationId, $this->strCfg('dmScope', Keys::DM_PER_PEER))
            : Keys::channelKey('msteams', $this->account, $conversationId);
        $ref = [
            'serviceUrl' => $serviceUrl,
            'conversationId' => $conversationId,
            'activityId' => (string) ($activity['id'] ?? ''),
            'tenantId' => (string) ($activity['conversation']['tenantId'] ?? ''),
        ];

        $command = Commands::parseText($content);
        if ($command !== null) {
            if ($command['name'] === 'pair') {
                $this->handlePairCommand($ref, (array) $command['args'], null);

                return;
            }
            $this->router->runCommand('msteams', $key, $ref + ['__actor' => ['userId' => $authorId, 'name' => (string) ($from['name'] ?? $authorId)]], $command);

            return;
        }

        $rawAttachments = \is_array($activity['attachments'] ?? null) ? $activity['attachments'] : [];
        if ($text === '' && $rawAttachments === []) {
            return;
        }

        // The door said this conversation is served; the floor says whether
        // THIS message is a prompt for us at all.
        if (!($isDm || $isMention || $this->boolCfg('respondWithoutMention', false))) {
            return;
        }

        $inboundId = (string) ($activity['id'] ?? '');
        if ($rawAttachments === []) {
            $this->router->inbound('msteams', $key, $ref, $text, $isMention, $inboundId);

            return;
        }
        // §8.7 v1 stance: platform-hosted media rides the settled batch;
        // Graph-hosted content is refused LOUD (a disclosed skip note),
        // never a silent drop, and never a dial with our token.
        $this->collectMediaAndDispatch($rawAttachments, $key, $ref, $text, $isMention, $inboundId);
    }

    /**
     * Card answers arrive as invoke activities (Action.Submit posts the
     * action data as `value`). C-1: the submitter first passes the
     * account's access floor (the same dmPolicy/group ladder the message
     * path applies, minus the pairing-code and mention arms — a submit is
     * a targeted interaction, not a DM asking to be served), and the
     * router then re-checks the actor against the roster AND the per-ask
     * nonce + conversation binding this card was minted with. A crafted
     * `value` naming anyone, or clicking a card from another conversation,
     * is refused fail-closed before the permission wire.
     */
    private function handleInvoke(array $activity): void
    {
        $value = \is_array($activity['value'] ?? null) ? $activity['value'] : [];
        $answer = Cards::parseSubmitValue($value);
        if ($answer === null) {
            $this->log->debug('msteams_invoke_unhandled', ['name' => (string) ($activity['name'] ?? '')]);

            return;
        }
        $from = \is_array($activity['from'] ?? null) ? $activity['from'] : [];
        $authorId = (string) ($from['aadObjectId'] ?? $from['id'] ?? '');
        $conversation = \is_array($activity['conversation'] ?? null) ? $activity['conversation'] : [];
        $conversationId = (string) ($conversation['id'] ?? '');
        $serviceUrl = \trim((string) ($activity['serviceUrl'] ?? ''));
        $conversationType = (string) ($conversation['conversationType'] ?? (string) ($activity['conversationType'] ?? 'personal'));
        $isDm = $conversationType === 'personal';
        $key = $isDm
            ? Keys::dmKey('msteams', $this->account, $authorId, $conversationId, $this->strCfg('dmScope', Keys::DM_PER_PEER))
            : Keys::channelKey('msteams', $this->account, $conversationId);

        if (!$this->invokeActorAllowed($isDm, $authorId, $conversationId)) {
            $this->log->warn('msteams_invoke_refused', ['ask' => $answer['askId'], 'user' => $authorId]);
            if ($conversationId !== '' && $serviceUrl !== '') {
                $this->rest->sendActivity($serviceUrl, $conversationId, [
                    'type' => 'message',
                    'from' => ['id' => $this->appId],
                    'textFormat' => 'markdown',
                    'text' => '⛔ You are not permitted to answer approvals here.',
                ])->then(null, fn (\Throwable $e) => $this->log->debug('msteams_invoke_refuse_failed', ['error' => $e->getMessage()]));
            }

            return;
        }
        $this->router->answerByAskId($answer['askId'], $answer['verdict'], [
            'userId' => $authorId,
            'name' => (string) ($from['name'] ?? ''),
        ], $answer['nonce'], $key, 'card');
    }

    /**
     * C-1 access floor for answer-carrying invokes: targeted submissions
     * have no mention semantics, so the message path's mention/degraded
     * arms do not apply — what applies is the whole dmPolicy ladder
     * (pairing WITHOUT the code notice) and the group row with its
     * disabled policy. Fail closed: unknown actor, unlisted group,
     * disabled policy all refuse.
     */
    private function invokeActorAllowed(bool $isDm, string $authorId, string $conversationId): bool
    {
        if ($authorId === '') {
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

                return $allow !== [] && \in_array($authorId, $allow, true);
            }

            return $this->pairing->isPaired($this->account, $authorId);
        }
        $groups = $this->mapCfg('groups');
        $row = \is_array($groups[$conversationId] ?? null) ? $groups[$conversationId] : null;
        if ($row === null && $this->strCfg('groupPolicy', 'allowlist') !== 'open') {
            return false;
        }

        return $row === null || (string) ($row['policy'] ?? 'allowlist') !== 'disabled';
    }

    /**
     * The §7.3 DM ladder, the SHARED code — every constant byte-identical
     * to the discord bundle's pairing (alphabet, length 8, hour TTL, three
     * pending, one reminder per hour). Returns true when the message may
     * proceed. Pairing is a GATE, not a prompt: an unpaired peer gets its
     * code notice (one per reminder window) and never reaches the model.
     */
    private function handleDmPolicy(string $authorId, string $serviceUrl, string $conversationId, string $content): bool
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
            'new', 'pending' => "Pairing required.\nYour code: `" . ($outcome['code'] ?? '') . "` (expires in 1 hour)\n"
                . 'An approver answers with: /pair approve ' . ($outcome['code'] ?? ''),
            'full' => 'Pairing requests are at their limit right now; try again later.',
            default => '', // 'silent' / 'paired': inside the reminder window, or raced onto the roster
        };
        if ($notice !== '') {
            // The notice IS the code's one legitimate surface (§7.3): it
            // rides raw REST, NOT safety->outbound — everywhere else
            // (transcripts, logs, channel chatter) the code is redacted,
            // because PairingCodes registered it as a secret.
            $this->rest->sendActivity($serviceUrl, $conversationId, [
                'type' => 'message',
                'from' => ['id' => $this->appId],
                'textFormat' => 'markdown',
                'text' => $notice,
            ])->then(null, fn (\Throwable $e) => $this->log->warn('pairing_notice_failed', ['error' => $e->getMessage()]));
            $this->log->info('pairing_requested', ['account' => $this->account, 'kind' => $outcome['kind']]);
        }

        return false;
    }

    /**
     * §8.3 group door: GROUPS ARE BLOCKED BY DEFAULT — a groupChat or
     * channel conversation is served only when `groupPolicy` says open OR
     * the conversation carries a `groups` row. A row may be 'disabled'
     * explicitly. `requireMention` defaults true per row and globally; the
     * degraded floor (§8.3 without the RSC consent) forces mention-only.
     */
    private function allowedGroup(string $conversationId, bool $isMention, bool $degraded): bool
    {
        $groups = $this->mapCfg('groups');
        $row = \is_array($groups[$conversationId] ?? null) ? $groups[$conversationId] : null;
        $groupPolicy = $this->strCfg('groupPolicy', 'allowlist');
        if ($row === null && $groupPolicy !== 'open') {
            $this->log->debug('msteams_group_blocked_default', ['conversation' => \substr($conversationId, 0, 24)]);

            return false;
        }
        if ($row !== null && (string) ($row['policy'] ?? 'allowlist') === 'disabled') {
            return false;
        }
        if ($degraded) {
            return $isMention;
        }
        $requireMention = $row === null ? true : (bool) ($row['requireMention'] ?? true);
        if ($requireMention && !$isMention) {
            return false;
        }
        if ($this->boolCfg('ignoreOtherMentions', true) && $this->mentionsSomebodyElse()) {
            return false; // addressed to someone else
        }

        return true;
    }

    /**
     * §8.7 v1 down-select: ≤4 images + ≤2 PDFs under `media.maxMb`, and
     * ONLY platform-hosted content (Bot Framework media hosts). A
     * Graph/SharePoint url gets a disclosed refusal note — the bytes need
     * delegated consent the bundle does not ask for until P3.5 — never a
     * silent drop, never a dial. Fetches run concurrently; the settled
     * batch becomes ONE prompt. Wire shape per entry:
     * {name, mime, data: strict-base64} (§9 media contract).
     *
     * @param list<mixed> $raw
     */
    private function collectMediaAndDispatch(array $raw, string $key, array $ref, string $text, bool $isMention, string $inboundId): void
    {
        $maxMb = (int) $this->nested('media', 'maxMb', 100);
        $maxBytes = \max(1, $maxMb) * 1048576;
        $notes = [];
        /** @var list<array{name: string, url: string, kind: string, promise: PromiseInterface}> $jobs */
        $jobs = [];
        $imageSlots = 4;
        $documentSlots = 2;
        foreach ($raw as $attachment) {
            if (!\is_array($attachment)) {
                continue;
            }
            $name = trim((string) ($attachment['name'] ?? '')) ?: 'attachment';
            $mime = strtolower(trim((string) ($attachment['contentType'] ?? '')));
            $url = trim((string) ($attachment['contentUrl'] ?? ($attachment['content'] ?? '')));
            if ($url === '') {
                $notes[] = "{$name}: no inline content (Graph-hosted media is a P3.5 leg)";
                continue;
            }
            $isImage = str_starts_with($mime, 'image/');
            $isPdf = $mime === 'application/pdf';
            if (!$isImage && !$isPdf) {
                $notes[] = "{$name}: unsupported type {$mime}";
                continue;
            }
            if ($isImage && $imageSlots <= 0) {
                $notes[] = "{$name}: beyond the 4-image cap";
                continue;
            }
            if ($isPdf && $documentSlots <= 0) {
                $notes[] = "{$name}: beyond the 2-document cap";
                continue;
            }
            $declared = (int) ($attachment['size'] ?? 0);
            if ($declared > $maxBytes) {
                $notes[] = "{$name}: declared " . (int) ceil($declared / 1048576) . " MB exceeds the {$maxMb} MB cap";
                continue;
            }
            if ($isImage) {
                $imageSlots--;
            } else {
                $documentSlots--;
            }
            $jobs[] = [
                'name' => $name,
                'url' => $url,
                'kind' => $isImage ? 'image' : 'document',
                'promise' => $this->rest->fetchBinary($url),
            ];
        }

        if ($jobs === []) {
            // Nothing fetchable — the note leg still speaks (disclosed), and
            // the text alone may be a complete prompt.
            $this->finishMedia($key, $ref, $text, $isMention, $inboundId, [], [], $notes);

            return;
        }

        $promises = \array_map(static fn (array $job): PromiseInterface => $job['promise'], $jobs);
        \React\Promise\all($promises)->then(function (array $settled) use ($key, $ref, $text, $isMention, $inboundId, $jobs, $notes, $maxBytes): void {
            $images = [];
            $documents = [];
            foreach ($settled as $index => $bytes) {
                $job = $jobs[$index];
                if (!\is_string($bytes)) {
                    continue;
                }
                if (\strlen($bytes) > $maxBytes) {
                    $notes[] = "{$job['name']}: actually " . (int) ceil(\strlen($bytes) / 1048576) . ' MB exceeds the cap';
                    continue;
                }
                $entry = ['name' => $job['name'], 'mime' => '', 'data' => \base64_encode($bytes)];
                if ($job['kind'] === 'image') {
                    $images[] = $entry;
                } else {
                    $documents[] = $entry;
                }
            }
            $this->finishMedia($key, $ref, $text, $isMention, $inboundId, $images, $documents, $notes);
        }, function (\Throwable $e) use ($key, $ref, $text, $isMention, $inboundId, $notes): void {
            // A failed batch NEVER silently eats the prompt: the text rides
            // on with every fetch named in the disclosed note (§7.8 law).
            $notes[] = 'media batch failed: ' . $e->getMessage();
            $this->finishMedia($key, $ref, $text, $isMention, $inboundId, [], [], $notes);
        });
    }

    /** @param list<string> $notes */
    private function finishMedia(string $key, array $ref, string $text, bool $isMention, string $inboundId, array $images, array $documents, array $notes): void
    {
        if ($notes !== []) {
            $disclosed = '[skipped: ' . \implode('; ', $notes) . ']';
            $text = $text === '' ? $disclosed : $text . "\n" . $disclosed;
            $this->log->info('msteams_media_downselect', ['kept' => \count($images) + \count($documents), 'skipped' => \count($notes)]);
        }
        if ($text === '' && $images === [] && $documents === []) {
            return;
        }
        $this->router->inbound('msteams', $key, $ref, $text, $isMention, $inboundId, $images, $documents);
    }

    /** Public door for the router's /pair text fallback (§7.3). */
    public function pairCommand(array $ref, array $args): void
    {
        $this->handlePairCommand($ref, $args, null);
    }

    /** @param array<string,mixed> $ref */
    private function handlePairCommand(array $ref, array $args, ?\Closure $reply): void
    {
        $action = \strtolower(\trim((string) ($args[0] ?? 'list')));
        if ($action === 'approve') {
            $code = \strtoupper(\trim((string) ($args[1] ?? '')));
            $paired = $code === '' ? null : $this->pairing->approve($this->account, $code);
            $text = $paired === null ? 'No pending pairing matches that code.' : "Paired {$paired}.";
            if ($reply !== null) {
                $reply($text);

                return;
            }
            $this->sendRaw((string) ($ref['serviceUrl'] ?? ''), (string) ($ref['conversationId'] ?? ''), $text);

            return;
        }

        $rows = $this->pairing->pending($this->account);
        $text = $rows === []
            ? 'No pending pairings.'
            : \implode("\n", \array_map(
                static fn (array $row): string => (string) $row['code'] . ' — user ' . (string) $row['userId'],
                $rows,
            ));
        if ($reply !== null) {
            $reply($text);

            return;
        }
        $this->sendRaw((string) ($ref['serviceUrl'] ?? ''), (string) ($ref['conversationId'] ?? ''), $text);
    }

    /** The approver-facing answers ride RAW (pairing codes are redacted everywhere else). */
    private function sendRaw(string $serviceUrl, string $conversationId, string $content): void
    {
        if ($serviceUrl === '' || $conversationId === '') {
            return;
        }
        $this->rest->sendActivity($serviceUrl, $conversationId, [
            'type' => 'message',
            'from' => ['id' => $this->appId],
            'textFormat' => 'markdown',
            'text' => $content,
        ])->then(null, fn (\Throwable $e) => $this->log->warn('pair_answer_failed', ['error' => $e->getMessage()]));
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

    public function appId(): string
    {
        return $this->appId;
    }

    /** The display name used for own-mention stripping (config, else the app id tail). */
    private function botName(): string
    {
        $name = $this->cfg['botName'] ?? null;

        return \is_string($name) && $name !== '' ? $name : 'bot';
    }

    private function isOurAuthor(string $fromId): bool
    {
        if ($fromId === '' || $this->appId === '') {
            return false;
        }
        if ($fromId === $this->appId) {
            return true;
        }
        foreach (self::BOT_ID_PREFIXES as $prefix) {
            if ($fromId === $prefix . $this->appId) {
                return true;
            }
        }

        return false;
    }

    private function hasBotPrefix(string $fromId): bool
    {
        foreach (self::BOT_ID_PREFIXES as $prefix) {
            if (\str_starts_with($fromId, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $activity */
    private function mentionsUs(array $activity): bool
    {
        $entities = \is_array($activity['entities'] ?? null) ? $activity['entities'] : [];
        foreach ($entities as $entity) {
            if (!\is_array($entity) || (string) ($entity['type'] ?? '') !== 'mention') {
                continue;
            }
            $mentioned = (string) ($entity['mentioned']['user']['id'] ?? '');
            if ($mentioned !== '' && $this->isOurAuthor($mentioned)) {
                return true;
            }
        }

        return false;
    }

    /** True when the activity currently being walked mentions somebody other than us. */
    private function mentionsSomebodyElse(): bool
    {
        foreach ($this->pendingEntityPeers as $peerId) {
            if ($peerId !== '' && !$this->isOurAuthor($peerId)) {
                return true;
            }
        }

        return false;
    }

    /** @var list<string> mention ids from the activity currently being walked */
    private array $pendingEntityPeers = [];

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
