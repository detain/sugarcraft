<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

use CandyCode\Bundles\MsTeams\Bridge\ConversationRouter;
use CandyCode\Bundles\MsTeams\Bridge\PlatformFace;
use CandyCode\Bundles\MsTeams\Bridge\StreamingFace;
use CandyCode\Bundles\MsTeams\Support\Keys;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\MediaSniff;
use CandyCode\Bundles\MsTeams\Support\OutboundChunker;
use CandyCode\Bundles\MsTeams\Support\PairingCodes;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Lang;

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
final class MsTeamsAccount implements PlatformFace, StreamingFace
{
    private const BOT_ID_PREFIXES = ['28:', 'app:'];

    /** Placeholder text of a progressive reply; the elapsed seconds ride after it on refresh. */
    private const WORKING_TEXT = 'working…';

    /** Shown in place of a placeholder whose turn ended without a reply. */
    private const ABANDONED_TEXT = 'No reply was produced.';

    /** The refresh tick: cheap, because the throttle below decides what actually reaches Teams. */
    private const PROGRESS_TICK_SECONDS = 1.0;

    /** At most one placeholder edit per this many seconds (the throttle law). */
    private const PROGRESS_GAP_SECONDS = 2.0;

    /**
     * conversationId → open progressive reply. At most one per conversation:
     * a second prompt while a turn runs rides the same placeholder.
     *
     * @var array<string, array{token: int, ref: array<string,mixed>, open: PromiseInterface, activityId: ?string, startedAt: float, lastAt: float, shown: string, timer: TimerInterface}>
     */
    private array $replies = [];

    private int $replySeq = 0;

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
        private readonly string $tenantId = '',
        /** Test seam for the throttle: returns seconds (float). Null = the wall clock. */
        private readonly ?\Closure $clock = null,
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
     * access floor. The configured ttl posture arms an expiry timer that
     * RETIRES the card clock-triggered, self-clamped to the core expiry
     * ceiling (see approvalTtlSeconds(), co-TTL code-chat §11.6) so the
     * card never advertises a window the core will not honor, and the same
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
        foreach ($this->replies as $state) {
            $this->loop->cancelTimer($state['timer']);
        }
        $this->replies = [];
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
        if ($type !== 'message' && $type !== 'invoke' && $type !== 'conversationUpdate') {
            return; // typing from humans, messageReaction… — not input
        }
        if (!$this->tenantAccepted($activity)) {
            return;
        }
        // Teams retries a slow POST: every activity id (commands and card
        // answers included) is claimed once, before any dispatch.
        $inboundId = (string) ($activity['id'] ?? '');
        if ($inboundId !== '' && !$this->router->claimInbound('msteams', $inboundId)) {
            $this->log->debug('msteams_activity_duplicate', ['type' => $type]);

            return;
        }
        if ($type === 'invoke') {
            $this->handleInvoke($activity);

            return;
        }
        if ($type === 'conversationUpdate') {
            $this->handleConversationUpdate($activity);

            return;
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
        } elseif (!$this->allowedGroup($conversationId, $authorId, $isMention, $degraded)) {
            return;
        }
        // The access floor passed: this conversation is one the bot serves,
        // so its reply address becomes eligible for proactive notify.
        $this->rememberConversation($activity, $conversationType);

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
                $this->handlePairCommand($ref, (array) $command['args'], $authorId, $isDm);

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
        if (!($isDm || $isMention || $this->boolCfg('respondWithoutMention', false) || $this->groupTakesPlainPrompts($conversationId, $degraded))) {
            return;
        }

        if ($rawAttachments === []) {
            $this->router->inbound('msteams', $key, $ref, $text, $isMention);

            return;
        }
        // §8.7 v1 stance: platform-hosted media rides the settled batch;
        // Graph-hosted content is refused LOUD (a disclosed skip note),
        // never a silent drop, and never a dial with our token.
        $this->collectMediaAndDispatch($rawAttachments, $key, $ref, $text, $isMention);
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
        $this->rememberConversation($activity, $conversationType);
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
        $row = $this->groupRow($conversationId);
        if ($row === null && $this->strCfg('groupPolicy', 'allowlist') !== 'open') {
            return false;
        }
        if ($row !== null && !self::groupRowAllows($row, $authorId)) {
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
     * §8.1 tenant floor: with a tenant configured, an activity whose tenant
     * differs (or names none) is refused before anything reads it. Fail closed.
     *
     * @param array<string,mixed> $activity
     */
    private function tenantAccepted(array $activity): bool
    {
        if ($this->tenantId === '') {
            return true;
        }
        $conversation = \is_array($activity['conversation'] ?? null) ? $activity['conversation'] : [];
        $channelData = \is_array($activity['channelData'] ?? null) ? $activity['channelData'] : [];
        $tenant = \is_array($channelData['tenant'] ?? null) ? $channelData['tenant'] : [];
        $tid = \is_string($conversation['tenantId'] ?? null)
            ? $conversation['tenantId']
            : (\is_string($tenant['id'] ?? null) ? $tenant['id'] : '');
        if ($tid !== '' && \strcasecmp($tid, $this->tenantId) === 0) {
            return true;
        }
        $this->log->warn('msteams_tenant_refused', ['tenantPresent' => $tid !== '']);

        return false;
    }

    /**
     * A group row with requireMention:false admits plain prompts once the
     * door has approved the conversation (§8.3). The degraded floor still
     * forces mention-only.
     */
    private function groupTakesPlainPrompts(string $conversationId, bool $degraded): bool
    {
        if ($degraded) {
            return false;
        }
        $groups = $this->mapCfg('groups');
        $row = \is_array($groups[$conversationId] ?? null) ? $groups[$conversationId] : null;

        return $row !== null
            && (string) ($row['policy'] ?? 'allowlist') !== 'disabled'
            && (bool) ($row['requireMention'] ?? true) === false;
    }

    /** The approver roster (the same list that answers tool asks). An empty roster answers nobody. */
    private function isApprover(string $actorId): bool
    {
        $roster = \array_map('strval', $this->listCfg('approvalRoster'));

        return $actorId !== '' && $roster !== [] && \in_array($actorId, $roster, true);
    }

    /**
     * §8.3 group door: GROUPS ARE BLOCKED BY DEFAULT — a groupChat or
     * channel conversation is served only when `groupPolicy` says open OR
     * the conversation carries a `groups` row. A row may be 'disabled'
     * explicitly. `requireMention` defaults true per row and globally; the
     * degraded floor (§8.3 without the RSC consent) forces mention-only.
     */
    private function allowedGroup(string $conversationId, string $authorId, bool $isMention, bool $degraded): bool
    {
        $row = $this->groupRow($conversationId);
        $groupPolicy = $this->strCfg('groupPolicy', 'allowlist');
        if ($row === null && $groupPolicy !== 'open') {
            $this->log->debug('msteams_group_blocked_default', ['conversation' => \substr($conversationId, 0, 24)]);

            return false;
        }
        if ($row !== null && (string) ($row['policy'] ?? 'allowlist') === 'disabled') {
            return false;
        }
        if ($row !== null && !self::groupRowAllows($row, $authorId)) {
            $this->log->debug('msteams_group_user_not_listed', ['conversation' => \substr($conversationId, 0, 24)]);

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
     * silent drop, never a dial. Each fetch settles on its own: a failed
     * job drops only its own attachment (disclosed) and its siblings still
     * ride the batch, which becomes ONE prompt. The declared contentType is
     * a hint; the bytes decide the mime on the wire (the serve re-sniffs and
     * refuses a mismatch), so absent or generic types are sniffed after the
     * fetch. Wire shape per entry: {name, mime, data: strict-base64}.
     *
     * @param list<mixed> $raw
     */
    private function collectMediaAndDispatch(array $raw, string $key, array $ref, string $text, bool $isMention): void
    {
        $maxMb = (int) $this->nested('media', 'maxMb', 100);
        $maxBytes = \max(1, $maxMb) * 1048576;
        $notes = [];
        /** @var list<array{name: string, promise: PromiseInterface}> $jobs */
        $jobs = [];
        $declaredImages = 0;
        $declaredDocuments = 0;
        $untypedFetches = 0;
        foreach ($raw as $attachment) {
            if (!\is_array($attachment)) {
                continue;
            }
            $name = \is_string($attachment['name'] ?? null) ? \trim($attachment['name']) : '';
            $name = $name === '' ? 'attachment' : $name;
            $declared = \is_string($attachment['contentType'] ?? null) ? \strtolower(\trim($attachment['contentType'])) : '';
            $url = self::inlineUrl($attachment);
            if ($url === '') {
                $notes[] = "{$name}: no inline content (Graph-hosted media is a P3.5 leg)";
                continue;
            }
            $untyped = $declared === '' || $declared === 'application/octet-stream';
            if ($untyped) {
                if ($untypedFetches >= 2) {
                    $notes[] = "{$name}: beyond the 2 untyped-attachment budget";
                    continue;
                }
                $untypedFetches++;
            } elseif (\str_starts_with($declared, 'image/')) {
                if ($declaredImages >= 4) {
                    $notes[] = "{$name}: beyond the 4-image cap";
                    continue;
                }
                $declaredImages++;
            } elseif ($declared === 'application/pdf') {
                if ($declaredDocuments >= 2) {
                    $notes[] = "{$name}: beyond the 2-document cap";
                    continue;
                }
                $declaredDocuments++;
            } else {
                $notes[] = "{$name}: unsupported type {$declared}";
                continue;
            }
            $declaredSize = (int) ($attachment['size'] ?? 0);
            if ($declaredSize > $maxBytes) {
                $notes[] = "{$name}: declared " . (int) \ceil($declaredSize / 1048576) . " MB exceeds the {$maxMb} MB cap";
                continue;
            }
            $jobs[] = [
                'name' => $name,
                'promise' => $this->rest->fetchBinary($url)->then(
                    static fn (string $bytes): ?string => $bytes,
                    function (\Throwable $e) use ($name): ?string {
                        $this->log->warn('msteams_media_fetch_failed', ['name' => $name, 'error' => $e->getMessage()]);

                        return null;
                    },
                ),
            ];
        }

        if ($jobs === []) {
            // Nothing fetchable — the note leg still speaks (disclosed), and
            // the text alone may be a complete prompt.
            $this->finishMedia($key, $ref, $text, $isMention, [], [], $notes);

            return;
        }

        $promises = \array_map(static fn (array $job): PromiseInterface => $job['promise'], $jobs);
        \React\Promise\all($promises)->then(function (array $settled) use ($key, $ref, $text, $isMention, $jobs, $notes, $maxBytes): void {
            $images = [];
            $documents = [];
            foreach ($settled as $index => $bytes) {
                $name = $jobs[$index]['name'];
                if (!\is_string($bytes)) {
                    $notes[] = "{$name}: fetch failed";
                    continue;
                }
                $mime = MediaSniff::sniff($bytes);
                if ($mime === null) {
                    $notes[] = "{$name}: bytes are not a supported image or PDF";
                    continue;
                }
                if (\strlen($bytes) > $maxBytes) {
                    $notes[] = "{$name}: actually " . (int) \ceil(\strlen($bytes) / 1048576) . ' MB exceeds the cap';
                    continue;
                }
                $isImage = \str_starts_with($mime, 'image/');
                if ($isImage && \count($images) >= 4) {
                    $notes[] = "{$name}: beyond the 4-image cap";
                    continue;
                }
                if (!$isImage && \count($documents) >= 2) {
                    $notes[] = "{$name}: beyond the 2-document cap";
                    continue;
                }
                $entry = ['name' => $name, 'mime' => $mime, 'data' => \base64_encode($bytes)];
                if ($isImage) {
                    $images[] = $entry;
                } else {
                    $documents[] = $entry;
                }
            }
            $this->finishMedia($key, $ref, $text, $isMention, $images, $documents, $notes);
        });
    }

    /** The inline url of one attachment: contentUrl, else a string content. Non-string content is absent. */
    private static function inlineUrl(array $attachment): string
    {
        $contentUrl = \is_string($attachment['contentUrl'] ?? null) ? \trim($attachment['contentUrl']) : '';
        if ($contentUrl !== '') {
            return $contentUrl;
        }

        return \is_string($attachment['content'] ?? null) ? \trim($attachment['content']) : '';
    }

    /** @param list<string> $notes */
    private function finishMedia(string $key, array $ref, string $text, bool $isMention, array $images, array $documents, array $notes): void
    {
        if ($notes !== []) {
            $disclosed = '[skipped: ' . \implode('; ', $notes) . ']';
            $text = $text === '' ? $disclosed : $text . "\n" . $disclosed;
            $this->log->info('msteams_media_downselect', ['kept' => \count($images) + \count($documents), 'skipped' => \count($notes)]);
        }
        if ($text === '' && $images === [] && $documents === []) {
            return;
        }
        $this->router->inbound('msteams', $key, $ref, $text, $isMention, null, $images, $documents);
    }

    // ── membership: welcome ──

    /**
     * conversationUpdate: the welcome fires when the bot itself is ADDED. A
     * removal (membersRemoved naming the bot) never welcomes, even in the
     * same activity. Duplicate delivery is absorbed by the inbound claim
     * that runs before this door.
     *
     * @param array<string,mixed> $activity
     */
    private function handleConversationUpdate(array $activity): void
    {
        if ($this->botListed($activity['membersRemoved'] ?? null)) {
            return;
        }
        if (!$this->botListed($activity['membersAdded'] ?? null)) {
            return;
        }
        $from = \is_array($activity['from'] ?? null) ? $activity['from'] : [];
        $authorId = (string) ($from['aadObjectId'] ?? $from['id'] ?? '');
        $conversation = \is_array($activity['conversation'] ?? null) ? $activity['conversation'] : [];
        $conversationId = (string) ($conversation['id'] ?? '');
        $serviceUrl = \trim((string) ($activity['serviceUrl'] ?? ''));
        if ($conversationId === '' || $serviceUrl === '') {
            return;
        }
        $conversationType = (string) ($conversation['conversationType'] ?? (string) ($activity['conversationType'] ?? 'personal'));
        // The bot was given this conversation by its own members: remember the
        // reply address now, so a later proactive notify can reach it.
        $this->rememberConversation($activity, $conversationType);

        $isDm = $conversationType === 'personal';
        if ($isDm && $this->strCfg('dmPolicy', 'pairing') === 'disabled') {
            return; // a disabled DM door has nothing to welcome anyone into
        }
        $lines = [Lang::t('channels.msteams.welcome.connected')];
        if ($isDm) {
            if ($this->strCfg('dmPolicy', 'pairing') === 'pairing' && !$this->pairing->isPaired($this->account, $authorId)) {
                $lines[] = Lang::t('channels.msteams.welcome.pairing_required');
            }
        } else {
            $lines[] = $this->groupServed($conversationId)
                ? Lang::t('channels.msteams.welcome.group_mention')
                : Lang::t('channels.msteams.welcome.group_not_allowlisted', ['path' => 'channels.msteams.groups']);
        }

        $this->rest->sendActivity($serviceUrl, $conversationId, [
            'type' => 'message',
            'from' => ['id' => $this->appId],
            'textFormat' => 'markdown',
            'text' => $this->safety->outbound(\implode("\n", $lines)),
        ])->then(null, fn (\Throwable $e) => $this->log->warn('welcome_failed', ['error' => $e->getMessage()]));
    }

    /** @param mixed $members the membersAdded / membersRemoved list */
    private function botListed(mixed $members): bool
    {
        if (!\is_array($members)) {
            return false;
        }
        foreach ($members as $member) {
            if (\is_array($member) && $this->isOurAuthor((string) ($member['id'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    // ── reply-address store (proactive notify) ──

    /**
     * Remember the reply address of a conversation this account has just
     * served. The stored ref is the only address notifyConversation() will
     * ever post to, so the serviceUrl a caller passes is never trusted.
     *
     * @param array<string,mixed> $activity
     */
    private function rememberConversation(array $activity, string $conversationType): void
    {
        $conversation = \is_array($activity['conversation'] ?? null) ? $activity['conversation'] : [];
        $conversationId = (string) ($conversation['id'] ?? '');
        $serviceUrl = \trim((string) ($activity['serviceUrl'] ?? ''));
        if ($conversationId === '' || $serviceUrl === '') {
            return;
        }
        $this->router->rememberTeamsConversation($this->account, $conversationId, [
            'serviceUrl' => $serviceUrl,
            'conversationId' => $conversationId,
            'conversationType' => $conversationType,
            'tenantId' => (string) ($conversation['tenantId'] ?? ''),
        ]);
    }

    /**
     * Proactive post into a conversation this account has served (the
     * background-job completion path). Refused, not guessed: a conversation
     * never seen by this account returns null, a seen one whose door has
     * since closed returns a rejected promise. The text takes the same
     * redaction and chunking as a reply.
     *
     * How a host calls it: obtain the ref from an INBOUND activity this
     * account already served, i.e. `['conversationId' => $activity['conversation']['id']]`
     * (the Teams conversation id, e.g. `19:…@thread.tacv2` or a personal chat id),
     * and call it on the account instance that received that activity (the
     * account name is part of the lookup). The bundle records each served
     * conversation in its session store, so the ref survives a restart, but
     * a conversation the bot has never received an activity in cannot be
     * notified. Only `conversationId` is read: the serviceUrl is taken from the
     * stored activity, never from the caller, so a host cannot redirect the
     * post to another service.
     *
     * @param array<string,mixed> $conversationRef at least `conversationId`; any caller serviceUrl is ignored
     *
     * @return PromiseInterface<void>|null null when this account has never seen the conversation
     */
    public function notifyConversation(array $conversationRef, string $text): ?PromiseInterface
    {
        $conversationId = (string) ($conversationRef['conversationId'] ?? '');
        $stored = $conversationId === '' ? null : $this->router->teamsConversation($this->account, $conversationId);
        if ($stored === null) {
            return null;
        }
        $isDm = (string) ($stored['conversationType'] ?? 'personal') === 'personal';
        $stillServed = $isDm
            ? $this->strCfg('dmPolicy', 'pairing') !== 'disabled'
            : $this->groupServed($conversationId);
        if (!$stillServed) {
            return \React\Promise\reject(new \RuntimeException('msteams notify refused: the conversation is no longer served'));
        }
        $ref = [
            'serviceUrl' => (string) ($stored['serviceUrl'] ?? ''),
            'conversationId' => $conversationId,
            'activityId' => '',
        ];
        $chunks = OutboundChunker::split($this->safety->outbound($text), $this->textChunkLimit(), $this->maxLinesPerMessage());

        return $this->postChunks($ref, $chunks);
    }

    // ── group allowlist helpers ──

    /** @return array<string,mixed>|null the groups row for a conversation, if any */
    private function groupRow(string $conversationId): ?array
    {
        $groups = $this->mapCfg('groups');

        return \is_array($groups[$conversationId] ?? null) ? $groups[$conversationId] : null;
    }

    /** Whether the conversation is served at all (the §8.3 door, without the mention arms). */
    private function groupServed(string $conversationId): bool
    {
        $row = $this->groupRow($conversationId);
        if ($row === null) {
            return $this->strCfg('groupPolicy', 'allowlist') === 'open';
        }

        return (string) ($row['policy'] ?? 'allowlist') !== 'disabled';
    }

    /**
     * Per-user allowlist for one group row (`groups.rows.<conversationId>.allowFrom`,
     * the same ID spellings as `access.allowFrom`). An unset or empty list
     * admits everyone, which is the behaviour before the list existed.
     *
     * @param array<string,mixed> $row
     */
    private static function groupRowAllows(array $row, string $authorId): bool
    {
        $raw = \is_array($row['allowFrom'] ?? null) ? $row['allowFrom'] : [];
        $allow = [];
        foreach ($raw as $value) {
            if (\is_string($value) || \is_int($value)) {
                $allow[] = (string) $value;
            }
        }
        if ($allow === []) {
            return true;
        }

        return $authorId !== '' && \in_array($authorId, $allow, true);
    }

    // ── StreamingFace: progressive replies (streamReplies, default off) ──

    /**
     * Post the placeholder for an admitted prompt and arm the refresh tick.
     * The post is asynchronous, so a reply that lands first still finds the
     * placeholder through the pending `open` promise.
     */
    public function beginReply(array $ref): void
    {
        if (!$this->boolCfg('streamReplies', false)) {
            return;
        }
        $conversationId = (string) ($ref['conversationId'] ?? '');
        $serviceUrl = (string) ($ref['serviceUrl'] ?? '');
        if ($conversationId === '' || $serviceUrl === '' || isset($this->replies[$conversationId])) {
            return;
        }
        $token = ++$this->replySeq;
        $now = $this->now();
        $open = $this->rest->sendActivity($serviceUrl, $conversationId, $this->textActivity(self::WORKING_TEXT))->then(
            static fn (array $sent): string => (string) ($sent['id'] ?? ''),
            function (\Throwable $e): string {
                $this->log->warn('reply_stream_open_failed', ['error' => $e->getMessage()]);

                return '';
            },
        );
        $timer = $this->loop->addPeriodicTimer(self::PROGRESS_TICK_SECONDS, fn () => $this->refreshReply($ref));
        $this->replies[$conversationId] = [
            'token' => $token,
            'ref' => $ref,
            'open' => $open,
            'activityId' => null,
            'startedAt' => $now,
            'lastAt' => $now,
            'shown' => self::WORKING_TEXT,
            'timer' => $timer,
        ];
        $open->then(function (string $activityId) use ($conversationId, $token): void {
            if (($this->replies[$conversationId]['token'] ?? null) === $token) {
                $this->replies[$conversationId]['activityId'] = $activityId !== '' ? $activityId : null;
            }
        });
    }

    public function hasOpenReply(array $ref): bool
    {
        return isset($this->replies[(string) ($ref['conversationId'] ?? '')]);
    }

    /**
     * One refresh tick: edit the placeholder with the elapsed seconds, but
     * never twice inside PROGRESS_GAP_SECONDS. Public so the throttle can be
     * driven directly under an injected clock.
     *
     * @param array<string,mixed> $ref
     */
    public function refreshReply(array $ref): void
    {
        $conversationId = (string) ($ref['conversationId'] ?? '');
        $state = $this->replies[$conversationId] ?? null;
        if ($state === null || $state['activityId'] === null) {
            return;
        }
        $now = $this->now();
        if ($now - $state['lastAt'] < self::PROGRESS_GAP_SECONDS) {
            return;
        }
        $text = self::WORKING_TEXT . ' ' . (int) \floor($now - $state['startedAt']) . 's';
        if ($text === $state['shown']) {
            return;
        }
        $this->replies[$conversationId]['lastAt'] = $now;
        $this->replies[$conversationId]['shown'] = $text;
        $this->rest->updateActivity((string) ($state['ref']['serviceUrl'] ?? ''), $conversationId, $state['activityId'], $this->textActivity($text))
            ->then(null, fn (\Throwable $e) => $this->log->debug('reply_stream_update_failed', ['error' => $e->getMessage()]));
    }

    /**
     * The final reply. The first chunk replaces the placeholder in place; if
     * that edit is refused for any reason, the chunk posts as a new message
     * instead, so the answer is never lost to a failed edit.
     *
     * @param list<string> $chunks
     *
     * @return PromiseInterface<void>
     */
    public function finishReply(array $ref, array $chunks): PromiseInterface
    {
        $conversationId = (string) ($ref['conversationId'] ?? '');
        $state = $this->replies[$conversationId] ?? null;
        unset($this->replies[$conversationId]);
        if ($state === null) {
            return $this->postChunks($ref, $chunks);
        }
        $this->loop->cancelTimer($state['timer']);
        if ($chunks === []) {
            $this->settlePlaceholder($ref, $state['open'], self::ABANDONED_TEXT);

            return \React\Promise\resolve(null);
        }
        $first = (string) \array_shift($chunks);

        return $state['open']->then(function (string $activityId) use ($ref, $first, $chunks, $conversationId): PromiseInterface {
            if ($activityId === '') {
                return $this->postChunks($ref, [$first, ...$chunks]);
            }
            return $this->rest->updateActivity((string) ($ref['serviceUrl'] ?? ''), $conversationId, $activityId, $this->textActivity($first))
                ->then(null, function (\Throwable $e) use ($ref, $first): PromiseInterface {
                    $this->log->warn('reply_stream_replace_failed', ['error' => $e->getMessage()]);

                    return $this->postChunks($ref, [$first]);
                })
                ->then(fn () => $this->postChunks($ref, $chunks));
        });
    }

    /** A turn that ended without a reply: stop the tick and say so in the placeholder. */
    public function abandonReply(array $ref): void
    {
        $conversationId = (string) ($ref['conversationId'] ?? '');
        $state = $this->replies[$conversationId] ?? null;
        if ($state === null) {
            return;
        }
        unset($this->replies[$conversationId]);
        $this->loop->cancelTimer($state['timer']);
        $this->settlePlaceholder($ref, $state['open'], self::ABANDONED_TEXT);
    }

    /** @param PromiseInterface<string> $open */
    private function settlePlaceholder(array $ref, PromiseInterface $open, string $text): void
    {
        $open->then(function (string $activityId) use ($ref, $text): void {
            if ($activityId === '') {
                return;
            }
            $this->rest->updateActivity((string) ($ref['serviceUrl'] ?? ''), (string) ($ref['conversationId'] ?? ''), $activityId, $this->textActivity($text))
                ->then(null, fn (\Throwable $e) => $this->log->debug('reply_stream_settle_failed', ['error' => $e->getMessage()]));
        });
    }

    /**
     * Sequential posts: each chunk waits for the one before it, and a failed
     * chunk is logged without stopping the rest.
     *
     * @param list<string> $chunks
     *
     * @return PromiseInterface<void>
     */
    private function postChunks(array $ref, array $chunks): PromiseInterface
    {
        $tail = \React\Promise\resolve(null);
        foreach ($chunks as $chunk) {
            $tail = $tail
                ->then(fn () => $this->deliver($ref, $chunk))
                ->then(null, fn (\Throwable $e) => $this->log->warn('deliver_failed', ['error' => $e->getMessage()]));
        }

        return $tail;
    }

    /** @return array<string,mixed> */
    private function textActivity(string $text): array
    {
        return [
            'type' => 'message',
            'from' => ['id' => $this->appId],
            'textFormat' => 'markdown',
            'text' => $text,
        ];
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : \microtime(true);
    }

    /** Public door for the router's /pair text fallback (§7.3). */
    public function pairCommand(array $ref, array $args, string $actorId, bool $isDm): void
    {
        $this->handlePairCommand($ref, $args, $actorId, $isDm);
    }

    /**
     * Pairing is an approver's act (§7.3): the roster that answers tool asks
     * answers pairings too, and an EMPTY roster answers nobody — the gate that
     * admits a peer to the bot must not default open. Codes are listed only in
     * a direct message; a group reply names no code.
     *
     * @param array<string,mixed> $ref
     * @param array<mixed>        $args
     */
    private function handlePairCommand(array $ref, array $args, string $actorId, bool $isDm): void
    {
        $serviceUrl = (string) ($ref['serviceUrl'] ?? '');
        $conversationId = (string) ($ref['conversationId'] ?? '');
        if (!$this->isApprover($actorId)) {
            $this->sendRaw($serviceUrl, $conversationId, '⛔ Only an approver can manage pairings.');

            return;
        }

        $action = \strtolower(\trim((string) ($args[0] ?? 'list')));
        if ($action === 'approve') {
            $code = \strtoupper(\trim((string) ($args[1] ?? '')));
            $paired = $code === '' ? null : $this->pairing->approve($this->account, $code);
            $this->sendRaw($serviceUrl, $conversationId, $paired === null ? 'No pending pairing matches that code.' : "Paired {$paired}.");

            return;
        }

        if (!$isDm) {
            $this->sendRaw($serviceUrl, $conversationId, 'Use a direct message to list pairing codes.');

            return;
        }
        $rows = $this->pairing->pending($this->account);
        $text = $rows === []
            ? 'No pending pairings.'
            : \implode("\n", \array_map(
                static fn (array $row): string => (string) $row['code'] . ' — user ' . (string) $row['userId'],
                $rows,
            ));
        $this->sendRaw($serviceUrl, $conversationId, $text);
    }

    /** Shutdown leg (dispose law): in-flight activity posts get a bounded window to land. */
    public function drainDeliveries(float $seconds): void
    {
        $this->rest->drain($seconds);
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
