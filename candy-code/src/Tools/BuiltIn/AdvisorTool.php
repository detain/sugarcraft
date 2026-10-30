<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\Commands\BtwHostCommand;
use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Util\TokenTracker;

/**
 * Ask one expert a question about THIS conversation and get its answer as a
 * tool result (C-PRV-3.d, roadmap W24).
 *
 * The model keeps working while the advisor thinks off to the side: this is a
 * single tool-less completion — the same transcript envelope `/btw` sends
 * through {@see BtwHostCommand::request()} (the bounded digest is the reuse) —
 * against the dedicated {@see \SugarCraft\Code\Cli\Bootstrap::advisorBackend()},
 * never the conversation's own tool-armed engine. An advisor that could run
 * tools is a second agent pretending to be a reference; the framing says
 * plainly that it answers from the digest shown and nothing else.
 *
 * WHY A TOOL RATHER THAN A SLASH COMMAND: `/btw` answers the USER; the model
 * itself has no way to consult anyone. A judgement call mid-task — "is this
 * migration ordering safe given what we discussed?" — currently gets either a
 * full {@see TaskTool} sub-agent (tool-armed, expensive, meant for WORK) or
 * silence. The Advisor is the cheap third thing: one completion, digest-only
 * sight, an answer the model weighs and may ignore. The answer is fenced with
 * {@see \SugarCraft\Code\Context\DelegatedOutputFence} because it re-enters the
 * conversation as another model's bytes, exactly as a sub-agent report does.
 *
 * OFFERED ONLY WHEN `advisorModel` IS SET — the point of the feature is a
 * deliberately DIFFERENT (usually cheaper or sharper) pair of eyes, so unlike
 * {@see \SugarCraft\Code\Cli\Bootstrap::titleBackend()} there is no auxiliary
 * fallback ladder: with no configured advisor model the tool is absent from the
 * wire, not silently pointed at the cheap default. It is therefore a member of
 * {@see ToolCatalog::CONDITIONAL_BUILDS}: classified and documented like every
 * tool, built only when the launch's context carries the backend.
 *
 * BILLED HONESTLY: the completion costs real money on a real provider, so the
 * reply's usage rides {@see ToolResult::withUsage()} — the audit-B4 channel
 * {@see TaskTool} uses for sub-agent spend. `Runtime::resultMessage()` copies
 * it onto the tool-result message, {@see EngineBackend} folds it into the
 * turn's step usages the instant the result settles (so later cap checks in
 * the SAME turn see it), and the settle accounting bills the turn sum to the
 * session's SpendLedger and usage records. An unbilled side call would be the
 * one spend path in the app the user could not see afterwards.
 *
 * CAPPED TWO WAYS, because an advisor call is cheap exactly when it is rare:
 * at most {@see MAX_CALLS_PER_TURN} dials per turn (fresh counter each turn,
 * bound through {@see DelegatesToEngine::withEngine()} the way `Task` is), and
 * refused once the session's spend has reached its `--max-cost` cap — checked
 * through the durable ledger (`usageTotals`, the same door `/status` reads)
 * plus the copies' own accumulated advisor spend this turn. E646 holds: there
 * is no blanket wall-clock timeout around the completion; the provider's own
 * per-read timeouts stand, as they do for every other backend call.
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::NoAsk, position: 44, gloss: 'consult a second model, tool-less and off a bounded transcript digest, on one question about this conversation; offered only when `advisorModel` is set')]
final readonly class AdvisorTool implements Tool, BuildsFromCatalog, DelegatesToEngine, TakesToolCallId
{
    public const NAME = 'Advisor';

    /** Advisor dials one turn may make; the box counter is fresh per turn. */
    public const MAX_CALLS_PER_TURN = 3;

    /**
     * The advisor's voice — the `system` row of the borrowed envelope. Mirrors
     * {@see BtwHostCommand::PROMPT}'s stance (OpenHands' `ask_agent` template)
     * with the two things that differ for a model-asking rather than a
     * user-asking consultation: the answer comes BACK to the asking agent, and
     * the answer is advice the agent weighs, never a mandate.
     */
    public const FRAMING = 'You advise an AI coding agent on ONE question about a conversation between a user and '
        . 'that agent. The question is not part of the conversation and the user will not see your answer; the '
        . 'agent will. You cannot run tools; answer from the conversation shown, state what you would verify '
        . 'before acting on your own answer, and say so plainly when it does not contain what you need.';

    private function __construct(
        private ?Backend $advisorBackend = null,
        /** @var (\Closure(string): array<int, \SugarCraft\Code\Message>)|null */
        private ?\Closure $transcript = null,
        /** @var (\Closure(string): array{calls: int, totalTokens: int, costUsd: float, unpriced: bool}|null)|null */
        private ?\Closure $sessionSpend = null,
        private ?EngineBackend $engine = null,
        /**
         * This turn's mutable box — `['left' => int, 'billedUsd' => float]` —
         * fresh from {@see withEngine()} every turn, shared by every reference
         * the copy holds (a readonly class may hold a mutable object; the
         * property never changes, its contents do). The in-process sibling of
         * the durable check, so the advisor's OWN spend counts against both
         * the per-turn cap and the session cap the same turn it is billed.
         *
         * @var \ArrayObject<string, int|float>|null
         */
        private ?\ArrayObject $turnBox = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): ?self
    {
        // The design's door: no configured advisor model, no tool. The launch
        // context carries a backend ONLY when Bootstrap::advisorBackend()
        // resolved one from `advisorModel` / $CANDY_CODE_ADVISOR_MODEL.
        if ($context->advisorBackend === null) {
            return null;
        }

        return new self(
            $context->advisorBackend,
            $context->advisorTranscript,
            $context->advisorSpend,
        );
    }

    public function withEngine(
        EngineBackend $engine,
        ?\Closure $heartbeat = null,
        ?\Closure $subAgentEmitter = null,
    ): self {
        // The per-turn binding (same channel `Task` uses — EngineBackend::turnTools
        // binds every DelegatesToEngine tool once per turn). A fresh box per
        // turn is the whole of the "per turn" in "at most N calls per turn".
        return new self(
            $this->advisorBackend,
            $this->transcript,
            $this->sessionSpend,
            $engine,
            new \ArrayObject(['left' => self::MAX_CALLS_PER_TURN, 'billedUsd' => 0.0]),
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Consult a second model about ONE question on this conversation and get its answer as advice.'
            . ' The advisor runs NO tools and sees only a bounded digest of the conversation so far plus your'
            . ' `question` — it reads like a colleague who has skimmed the transcript, not like an agent who'
            . ' checked the code. Ask it what it would catch: design judgement ("is ordering this migration'
            . ' safe given the lock discussion above?"), a second read on a plan, what a reviewer would flag.'
            . ' Do NOT ask it for facts a tool can fetch (read the file), and never treat its answer as the'
            . ' user\'s approval. At most ' . self::MAX_CALLS_PER_TURN . ' calls per turn; refused once the'
            . ' session\'s spend cap is reached. Offered only when the `advisorModel` setting names a model.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The one thing to ask, self-contained: name the decision or the risk you'
                        . ' want a second read on, and any constraint the advisor cannot infer from the digest',
                ],
            ],
            'required' => ['question'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $callId = (string) ($args['id'] ?? '');
        if ($this->advisorBackend === null) {
            return new ToolResult($callId, 'Advisor is not wired on this launch; it is offered only when the `advisorModel` setting names a model', true);
        }
        if ($this->engine === null || $this->turnBox === null) {
            return new ToolResult($callId, 'Advisor is not bound to a turn on this launch, so it has no conversation to advise about', true);
        }
        $sessionId = $this->engine->sessionId();
        if ($sessionId === null) {
            return new ToolResult($callId, 'this conversation has no session, so the advisor has no transcript to read', true);
        }

        $question = trim(\is_string($args['question'] ?? null) ? $args['question'] : '');
        if ($question === '') {
            return new ToolResult($callId, 'Advisor needs a non-empty `question`', true);
        }

        // Per-turn cap BEFORE the money check: a turn that has used its dials
        // should not pay a ledger read to learn it. The counter is spent at
        // DIAL time, not at success time — a failed provider call still cost
        // (or at least attempted to), and a retry loop on a broken advisor is
        // exactly what the cap exists to bound.
        $box = $this->turnBox;
        if ((int) $box['left'] <= 0) {
            return new ToolResult($callId, sprintf(
                'Advisor may be called at most %d times per turn, and this turn has used them all. Ask your best-'
                    . 'informed question; the answer arrives as advice you may weigh or ignore.',
                self::MAX_CALLS_PER_TURN,
            ), true);
        }

        // The session spend cap, through the durable ledger the `/status`
        // command reads — the in-flight turn's OTHER spend settles there too,
        // so a mid-turn read is a LOWER bound on where the session stands; the
        // advisor's own dials this turn ride the box to close the rest of the
        // gap. Same fail-open-for-unreported doctrine as every other cap site
        // ({@see SpendLedger::capReached()}): a budget guard, not a security
        // control, so a store that answers nothing does not veto advice.
        $cap = \SugarCraft\Code\Cli\Bootstrap::maxCostUsd();
        if ($cap !== null && SpendLedger::isUsableCap($cap) && $this->sessionSpend !== null) {
            $tracker = new TokenTracker();
            try {
                $totals = ($this->sessionSpend)($sessionId);
            } catch (\Throwable) {
                $totals = null;
            }
            if (\is_array($totals)) {
                $tracker->addTotalUsage((int) ($totals['totalTokens'] ?? 0), (float) ($totals['costUsd'] ?? 0.0));
                if ((int) ($totals['unpriced'] ?? 0) > 0) {
                    $tracker->noteUnpricedUsage();
                }
            }
            $tracker->addTotalUsage(0, (float) $box['billedUsd']);
            if (SpendLedger::new()->capReached($tracker, $cap)) {
                return new ToolResult($callId, sprintf(
                    'the advisor call was refused: this session has reached its %s spend cap (the advisor is a'
                        . ' real billed completion on the `advisorModel`), and asking anyway is not this tool\'s'
                        . ' call to make. Report the limit to the user instead.',
                    '$' . $cap,
                ), true);
            }
        }

        $history = [];
        if ($this->transcript !== null) {
            try {
                $history = ($this->transcript)($sessionId);
            } catch (\Throwable) {
                // The digest is the advisor's ONLY sight; an unreadable store
                // degrades to "(empty)", the shape the envelope already
                // answers — the advisor will say the transcript does not
                // contain the answer, which is honest about the digest, not a
                // crash. ExecReviewer's transcript catch takes the same stance.
                $history = [];
            }
        }

        $box['left'] = (int) $box['left'] - 1;
        try {
            // The synchronous dial: execute() runs in the turn process (the
            // tool is not ParallelSafe), and BtwHostCommand's own request
            // builder is the envelope — bounded digest, fenced question, the
            // no-tools stance already written into it.
            $reply = $this->advisorBackend->complete(BtwHostCommand::request($question, $history, self::FRAMING));
        } catch (\Throwable $e) {
            return new ToolResult($callId, sprintf('the advisor call failed: %s', $e->getMessage()), true);
        }

        $answer = trim($reply->content);
        // Audit B4, the TaskTool channel: the completion billed, so the cost
        // rides the result even when the answer came back empty — and into the
        // box, so this turn's NEXT call sees it against the session cap.
        $spent = $reply->usage ?? null;
        if ($spent !== null) {
            $box['billedUsd'] = (float) $box['billedUsd'] + $spent->costUsd;
        }
        if ($answer === '') {
            $refusal = (new ToolResult($callId, 'the advisor returned no answer', true));
            return $spent === null ? $refusal : $refusal->withUsage($spent);
        }

        return (new ToolResult(
            $callId,
            \SugarCraft\Code\Context\DelegatedOutputFence::wrap($answer),
        ))->withUsage($spent);
    }
}
