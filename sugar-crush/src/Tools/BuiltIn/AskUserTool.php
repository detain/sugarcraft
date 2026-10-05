<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\RelaysPermissionAsks;
use SugarCraft\Crush\Tools\TakesToolCallId;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The model asks the person at the keyboard one question and waits for the
 * answer (roadmap 5.7-2): a fact the code cannot answer, a choice between
 * designs, a confirmation before a plan commits to something.
 *
 * THE QUESTION RIDES THE 1.C ASK CHANNEL, NOT A CHANNEL OF ITS OWN. The tool
 * puts it to the approver the turn runs with — the same closure a gate `Ask`
 * reaches ({@see RelaysPermissionAsks}): in the TUI the turn child's
 * {@see \SugarCraft\Crush\Backend\ChildChannel}, so the question crosses the
 * socket as an `ask` frame and opens the 1.C-2 modal; under
 * `sugarcrush serve` the same frame becomes the durable
 * `permission.requested` event every client sees. The reply vocabulary is the
 * modal's, read as an answer:
 *
 * - `y` (a permitting reply) takes option 1, the recommended one — or "yes"
 *   for a question with no options;
 * - `r` and a typed note is the answer in the user's own words; a note that
 *   is just an option's number picks that option;
 * - `n` declines, which the model reads as "no" for a yes/no question;
 * - nobody answering (the turn ended under the question) is an error result,
 *   never an answer.
 *
 * PERMISSION CLASS: no-ask. The call itself is the prompt, so a gate `Ask`
 * in front of it would put the same person two questions for one; under
 * `plan` it must run, since asking is half of what plan mode is for. A
 * `permissionRules` Deny still turns it off.
 *
 * WHO CAN BE ASKED. Without an approver, under `dont-ask`, or in a run
 * {@see withoutInteractiveUser()} marked (the `-p` path,
 * {@see \SugarCraft\Crush\Cli\NonInteractive}), the call fails closed with a
 * result telling the model to decide for itself and say what it assumed — a
 * question nobody can answer must not stall a headless run or be read as a
 * "no". It is a {@see DelegatesToEngine} tool only to read the turn's
 * permission mode; the side effect of that interface is wanted too: every
 * engine-bound tool is withheld from Task sub-agents and workflow stages, and
 * a sub-agent must not interrupt the user directly (Kilo's `question: false`,
 * Claude Code's subagent tool removals).
 *
 * NOT YET REACHABLE BY A PERSON: the engine does not bind its approver to
 * this tool (only {@see withPermissionApprover()} does), so a live call takes
 * the no-approver branch until it does.
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::NoAsk, position: 21, gloss: 'put one question to the user, with optional choices, and wait for the answer')]
final readonly class AskUserTool implements Tool, BuildsFromCatalog, DelegatesToEngine, RelaysPermissionAsks, TakesToolCallId
{
    public const NAME = 'AskUser';

    public const MAX_QUESTION_CHARS = 2000;

    public const MAX_OPTIONS = 6;

    public const MAX_OPTION_CHARS = 200;

    /** The one-line label the modal's call row shows is cut at this many characters. */
    private const LABEL_CHARS = 120;

    /**
     * @param ?\Closure(ToolCall, HookResult): mixed $approver the turn's approver; null when nothing can answer
     * @param ?PermissionMode                        $mode     the turn's permission mode, null when unknown
     * @param string                                 $noUser   why nobody can be asked in this run; '' when someone might
     */
    private function __construct(
        private ?\Closure $approver = null,
        private ?PermissionMode $mode = null,
        private string $noUser = '',
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return self::new();
    }

    /**
     * Bound per turn: the copy knows the turn's permission mode, and keeps
     * whatever approver and headless marking this one carries.
     */
    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): self
    {
        return new self($this->approver, $engine->permissionGate()?->mode(), $this->noUser);
    }

    /**
     * @param \Closure(ToolCall, HookResult): mixed $approver
     */
    public function withPermissionApprover(\Closure $approver): self
    {
        return new self($approver, $this->mode, $this->noUser);
    }

    /**
     * A copy that never asks, whatever approver it is later bound to:
     * `$why` says why nobody can answer here ("this is a non-interactive
     * `-p` run"), and the model reads it.
     */
    public function withoutInteractiveUser(string $why): self
    {
        return new self($this->approver, $this->mode, trim($why) === '' ? 'nobody is at the keyboard' : trim($why));
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Ask the user one question and wait for the answer. Use it when the work depends on something '
            . 'the code cannot tell you — a requirement, a preference, a choice between designs — and a wrong '
            . 'guess would be expensive; do not use it for things you can find out by reading the project. One '
            . 'question per call. When there are clear alternatives, pass them as `options`, put the one you '
            . 'recommend first and say why in the question; the user can still answer in their own words. The '
            . 'answer comes back as the result. If nobody can answer (a non-interactive run), decide yourself '
            . 'and state the assumption in your reply.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => sprintf('The question, complete on its own (at most %d characters)', self::MAX_QUESTION_CHARS),
                ],
                'options' => [
                    'type' => 'array',
                    'description' => sprintf(
                        'Optional choices, 2 to %d, the recommended one first (each at most %d characters)',
                        self::MAX_OPTIONS,
                        self::MAX_OPTION_CHARS,
                    ),
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['question'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $refusal = $this->whyNobodyCanAnswer();
        if ($refusal !== null) {
            return new ToolResult('', $refusal, true);
        }

        try {
            [$question, $options] = self::parse($args);
        } catch (\InvalidArgumentException $e) {
            return new ToolResult('', 'Error: ' . $e->getMessage() . '. Nothing was asked.', true);
        }

        $callId = is_string($args['id'] ?? null) ? $args['id'] : '';
        $call = new ToolCall($callId, self::NAME, [
            'description' => self::label('The agent asks: ' . $question),
            'question' => $question,
            'options' => $options,
        ]);

        try {
            /** @var \Closure(ToolCall, HookResult): mixed $approver */
            $approver = $this->approver;
            $verdict = ApprovalVerdict::of($approver($call, HookResult::ask(self::prompt($question, $options))));
        } catch (\Throwable $e) {
            $verdict = ApprovalVerdict::unanswered('the question could not be put: ' . $e->getMessage());
        }

        return self::answer($verdict, $options);
    }

    /**
     * The answer the model reads for `$verdict` — see the class docblock for
     * the reply vocabulary.
     *
     * @param list<string> $options
     */
    public static function answer(ApprovalVerdict $verdict, array $options): ToolResult
    {
        if ($verdict->isUnanswered()) {
            $why = $verdict->feedback === '' ? 'the turn ended before it was answered' : $verdict->feedback;

            return new ToolResult('', "Nobody answered the question ({$why}). Decide yourself, state the assumption in your reply, and go on.", true);
        }

        $typed = self::typedAnswer($verdict->feedback);
        if ($typed === null) {
            // Feedback that is not the user's words: a refusal the harness
            // wrote (a parallel member with no channel of its own).
            return new ToolResult('', "The question could not be put to the user: {$verdict->feedback}. Decide yourself, state the assumption in your reply, and go on.", true);
        }

        if ($typed !== '') {
            $picked = self::pickedOption($typed, $options);

            return new ToolResult('', $picked === null
                ? 'The user answered: ' . $typed
                : sprintf('The user chose option %d: %s', $picked + 1, $options[$picked]));
        }

        if ($verdict->permits()) {
            return new ToolResult('', $options === []
                ? 'The user answered: yes'
                : 'The user chose option 1: ' . $options[0]);
        }

        return new ToolResult('', 'The user declined the question. Read that as "no" if it was a yes/no question; '
            . 'otherwise do not ask it again — decide yourself and state the assumption in your reply.');
    }

    /** Why this call cannot be put to anyone, or null when it can. */
    private function whyNobodyCanAnswer(): ?string
    {
        $fallback = ' Decide yourself, state the assumption in your reply, and go on.';
        if ($this->noUser !== '') {
            return "No one can answer here: {$this->noUser}." . $fallback;
        }
        if ($this->mode === PermissionMode::DontAsk) {
            return 'No one can answer here: the session runs in `dont-ask` mode, which puts nothing to the user.' . $fallback;
        }
        if ($this->approver === null) {
            return 'No one can answer here: no interactive user is attached to this run.' . $fallback;
        }

        return null;
    }

    /**
     * The modal's question text. The keys come first: the modal shows only
     * the first rows of a long prompt, and a question whose keys were cut off
     * could not be answered on purpose.
     *
     * @param list<string> $options
     */
    private static function prompt(string $question, array $options): string
    {
        $keys = $options === []
            ? 'y = yes · n = no / decline · r = type an answer'
            : 'y = option 1 · r = type an answer or an option number · n = decline';

        $lines = [$keys, '', $question];
        if ($options !== []) {
            $lines[] = '';
            foreach ($options as $i => $option) {
                $lines[] = sprintf('%d. %s%s', $i + 1, $option, $i === 0 ? ' (recommended)' : '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The user's own words in a refusal's feedback: '' for none, null when
     * the feedback is a reason the harness wrote rather than the user.
     */
    private static function typedAnswer(string $feedback): ?string
    {
        if ($feedback === '') {
            return '';
        }

        // The label ApprovalVerdict puts on a person's note, derived from the
        // verdict itself so a reworded label cannot strand this check.
        $label = substr(ApprovalVerdict::rejectedByUser('x')->feedback, 0, -1);

        return str_starts_with($feedback, $label) ? trim(substr($feedback, \strlen($label))) : null;
    }

    /**
     * @param list<string> $options
     */
    private static function pickedOption(string $typed, array $options): ?int
    {
        if (preg_match('/\A\s*(\d+)\s*[.)]?\s*\z/', $typed, $m) === 1) {
            $index = (int) $m[1] - 1;

            return isset($options[$index]) ? $index : null;
        }

        foreach ($options as $i => $option) {
            if (strcasecmp(trim($typed), $option) === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function parse(array $args): array
    {
        $question = $args['question'] ?? null;
        if (!is_string($question) || trim($question) === '') {
            throw new \InvalidArgumentException('`question` must be a non-empty string');
        }
        $question = self::clean($question);
        if (mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            throw new \InvalidArgumentException(sprintf('`question` is longer than %d characters', self::MAX_QUESTION_CHARS));
        }

        $raw = $args['options'] ?? [];
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('`options` must be a list of strings');
        }

        $options = [];
        foreach ($raw as $option) {
            if (!is_string($option) || trim($option) === '') {
                throw new \InvalidArgumentException('every option must be a non-empty string');
            }
            $option = (string) preg_replace('/\s+/u', ' ', self::clean($option));
            if (mb_strlen($option) > self::MAX_OPTION_CHARS) {
                throw new \InvalidArgumentException(sprintf('an option is longer than %d characters', self::MAX_OPTION_CHARS));
            }
            foreach ($options as $seen) {
                if (strcasecmp($seen, $option) === 0) {
                    throw new \InvalidArgumentException("the option \"{$option}\" is listed twice");
                }
            }
            $options[] = $option;
        }

        if ($options !== [] && (\count($options) < 2 || \count($options) > self::MAX_OPTIONS)) {
            throw new \InvalidArgumentException(sprintf('`options` needs 2 to %d choices, or none', self::MAX_OPTIONS));
        }

        return [$question, $options];
    }

    /**
     * Model text on its way to a person: control bytes dropped (line breaks
     * kept), so the modal and a server client draw what the model meant
     * rather than what a stray escape would make of it.
     */
    private static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'));

        return trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text));
    }

    private static function label(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($line) > self::LABEL_CHARS ? mb_substr($line, 0, self::LABEL_CHARS - 1) . '…' : $line;
    }
}
