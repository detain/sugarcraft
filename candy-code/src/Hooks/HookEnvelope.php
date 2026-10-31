<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks;

use SugarCraft\Code\Support\HookContextFiles;

/**
 * The JSON a hook answers with, read once for every transport that can carry it.
 *
 * C-EXT-6.c (LATE) moved this out of {@see ScriptHook}, where it began as the
 * stdout parser of exit 0, because a hook that POSTs the same payload receives
 * its verdict the same way: as one JSON object on the response body carrying
 * `decision` / `reason` / `continue` / `updatedInput` / `additionalContext`.
 * Two readers of that shape is two answers to "what did the hook say", and the
 * day they disagree is the day a guard blocks under one transport and approves
 * under the other. {@see ScriptHook::fromEnvelope()} and
 * {@see HttpHook::execute()} are the two callers; neither holds policy.
 *
 * WHAT LIVES HERE AND WHAT DOES NOT. The keys, the decisions, the precedence
 * and the malformed envelope's refusal are the shared contract. The three
 * things that belong to a PRODUCER and not to a format stay with the producer
 * and are handed in: whose name the messages carry (`$hookName`), the question
 * an `ask` with no reason falls back to (`$questionFallback`), and where a
 * `systemMessage` is shown (`$noticeRecorder` — a script hook has the TUI
 * notice sink to record into; nothing in this class reaches for a sink of its
 * own, which is how a row could be written by a path its caller cannot audit).
 *
 * EVERY BYTE THAT CROSSES IS BOUNDED by the figure the exit-code twin of that
 * field already uses — the refusal reason at
 * {@see ScriptHook::MAX_DENY_REASON_BYTES}, the question at
 * {@see ScriptHook::MAX_ASK_PROMPT_BYTES}, the note at
 * {@see HookResult::MAX_ADDITIONAL_CONTEXT_BYTES}, and a rewrite through
 * {@see rewrite()}, refused over its ceiling rather than truncated. Those
 * constants stay on {@see ScriptHook}, which is where their rationale is
 * written; a second copy of a number here would be a bound nobody is holding
 * anyone to any more.
 *
 * @see ScriptHook::allowOrEnvelope()
 * @see HttpHook
 */
final class HookEnvelope
{
    /**
     * The keys that make an object an envelope rather than a note.
     *
     * Aliased from {@see ScriptHook} so the parser and the exit-code table
     * cannot drift apart by one spelling added on one side only.
     *
     * @var list<string>
     */
    public const KEYS = ScriptHook::ENVELOPE_KEYS;

    /**
     * The decisions an envelope may ask for.
     *
     * @var list<string>
     */
    public const DECISIONS = ScriptHook::ENVELOPE_DECISIONS;

    /**
     * @param \Closure(string): void|null $noticeRecorder shows a `systemMessage`
     *        to the human; null means this producer has nowhere to show one
     * @param \Closure(): string|null $questionFallback the question an `ask`
     *        with no reason asks; null falls back to the generic wording
     */
    public function __construct(
        private readonly string $hookName,
        private readonly ?\Closure $noticeRecorder = null,
        private readonly ?\Closure $questionFallback = null,
    ) {
    }

    /**
     * $body as a JSON envelope, or null when it is not one.
     *
     * THE BAR IS HIGH, and it is high for the model's sake as much as the
     * hook's: the whole trimmed body must be one JSON OBJECT carrying at least
     * one of {@see KEYS}. A hook that prints JSON-as-context — a linter's
     * `{"errors":[…]}` — must keep reaching the model as exactly that, byte for
     * byte, so an object with none of the known keys, a list, or JSON
     * surrounded by any other text answers null here.
     *
     * Decoded to OBJECTS, not arrays, because the arrays form throws away the
     * one distinction `updatedInput` needs: `{}` (run with no arguments) and
     * `[]` (not an argument map) both decode to `[]` — the same reason
     * {@see rewrite()} reads the opening brace.
     */
    public static function read(string $body): ?\stdClass
    {
        if (!str_starts_with($body, '{')) {
            return null;
        }

        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!$decoded instanceof \stdClass) {
            return null;
        }

        foreach (self::KEYS as $key) {
            if (property_exists($decoded, $key)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * The verdict an envelope asks for. Precedence, strongest first:
     * `continue: false` (stop — refuses the call too), a refusing `decision`,
     * `ask` (carrying any `updatedInput` as a PROPOSAL the chain re-scans,
     * {@see HookResult::ask()}), then `updatedInput` (a MODIFY), then a plain
     * allow carrying `additionalContext`.
     *
     * A refusal carries no note, exactly as exit 2 does. A JSON ALLOW IS NOT
     * PERMISSION OVER THE GATE: `decision: allow` is one hook not objecting,
     * and {@see HookRegistry::executeHooks()} still reaches
     * BuiltIn\PermissionGateHook after it — a config file cannot widen a
     * session's permission mode by printing a word.
     *
     * @param int $replacedBytes the byte length of the tool arguments a rewrite
     *        would replace, which is what makes {@see rewrite()}'s ceiling
     *        derived rather than flat
     */
    public function verdict(\stdClass $envelope, int $replacedBytes): HookResult
    {
        $specific = $envelope->hookSpecificOutput ?? null;
        if ($specific !== null && !$specific instanceof \stdClass) {
            return $this->malformed('"hookSpecificOutput" must be an object');
        }

        $field = static function (string $specificKey, string $topKey) use ($envelope, $specific): mixed {
            if ($specific !== null && isset($specific->{$specificKey})) {
                return $specific->{$specificKey};
            }

            return $envelope->{$topKey} ?? null;
        };

        $continue = $envelope->continue ?? null;
        $stopReason = $envelope->stopReason ?? null;
        $decision = $field('permissionDecision', 'decision');
        $reason = $field('permissionDecisionReason', 'reason');
        $note = $field('additionalContext', 'additionalContext');
        $updated = $field('updatedInput', 'updatedInput');

        // ISS-090(e): TOP LEVEL ONLY — Claude Code documents both as envelope
        // fields, not hookSpecificOutput ones, and reading them through
        // $field() would invent a per-tool spelling the spec does not carry.
        $suppress = $envelope->suppressOutput ?? null;
        $systemMessage = $envelope->systemMessage ?? null;

        $typed = [
            'continue' => [$continue, 'bool'],
            'stopReason' => [$stopReason, 'string'],
            'decision' => [$decision, 'string'],
            'reason' => [$reason, 'string'],
            'additionalContext' => [$note, 'string'],
            'suppressOutput' => [$suppress, 'bool'],
            'systemMessage' => [$systemMessage, 'string'],
        ];
        foreach ($typed as $key => [$value, $type]) {
            if ($value !== null && get_debug_type($value) !== $type) {
                return $this->malformed(sprintf(
                    '"%s" must be a %s, not %s',
                    $key,
                    $type === 'bool' ? 'boolean' : 'string',
                    get_debug_type($value),
                ));
            }
        }

        if ($updated !== null && !$updated instanceof \stdClass) {
            return $this->malformed('"updatedInput" must be a JSON object of tool arguments');
        }

        $reason = trim((string) $reason);
        $verdict = $decision === null ? 'allow' : strtolower(trim((string) $decision));
        if (!in_array($verdict, self::DECISIONS, true)) {
            return $this->malformed(sprintf(
                '"decision" must be one of %s, not "%s"',
                implode(', ', self::DECISIONS),
                ScriptHook::clip((string) $decision, 60),
            ));
        }

        // C-EXT-6.a: a string `systemMessage` is HONORED — as a notice to the
        // HUMAN, never as model context. Placed after every malformed return
        // above so a broken envelope records nothing, and before every verdict
        // below so an allow, a stop, a deny and an ask all still show the line
        // its hook wrote for the person at the terminal.
        if ($systemMessage !== null && trim($systemMessage) !== '' && $this->noticeRecorder !== null) {
            ($this->noticeRecorder)($systemMessage);
        }

        if ($continue === false) {
            $stopReason = trim((string) $stopReason);

            return HookResult::stop(
                ScriptHook::clip(
                    $stopReason !== '' ? $stopReason
                        : ($reason !== '' ? $reason : "Hook {$this->hookName} stopped the turn"),
                    ScriptHook::MAX_DENY_REASON_BYTES,
                ),
                ScriptHook::clip($stopReason, ScriptHook::MAX_DENY_REASON_BYTES),
            );
        }

        if ($verdict === 'deny' || $verdict === 'block') {
            return HookResult::deny(
                $reason !== '' ? ScriptHook::clip($reason, ScriptHook::MAX_DENY_REASON_BYTES)
                    : "Hook {$this->hookName} blocked the call",
            );
        }

        // ISS-090(e): `suppressOutput: true` kills the hook's output. Our only
        // output-borne surface is the additional-context note; the deny reason
        // and the ask question are verdicts, not output, and Claude Code's own
        // renderer keeps showing those. C-EXT-6.a completes the pair: a notice
        // is not output, so suppressing does not hide it.
        if ($suppress === true) {
            $note = null;
        }

        $context = HookContextFiles::bound(trim((string) $note), HookResult::MAX_ADDITIONAL_CONTEXT_BYTES);

        $rewrite = null;
        if ($updated !== null) {
            $rewrite = self::rewrite(
                $this->hookName,
                json_encode($updated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                $replacedBytes,
            );
            if (!$rewrite->isModified()) {
                return $rewrite;
            }
        }

        if ($verdict === 'ask') {
            return HookResult::ask(
                $reason !== '' ? ScriptHook::clip($reason, ScriptHook::MAX_ASK_PROMPT_BYTES) : $this->defaultQuestion(),
                $rewrite?->modifiedInput,
                $context,
            );
        }

        return $rewrite !== null
            ? $rewrite->withContextSet($context)
            : HookResult::allow('', $context);
    }

    /**
     * The rewrite an `updatedInput` (or an exit-4 script's stdout) settles to,
     * or the refusal it earns when it cannot be honoured.
     *
     * THE CEILING IS DERIVED, NOT FLAT, and that is the whole reason a rewrite
     * bound is defensible at all: a sanitiser that changes the `file_path` of a
     * 300 KB `Write` and leaves the body alone has to print the body back, so
     * its rewrite is necessarily about the size of the call it replaces.
     * {@see ScriptHook::MIN_REWRITE_BYTES} is only the floor under that, so a
     * small call still gets room to work.
     *
     * A rewrite is REFUSED over the ceiling rather than truncated: cutting
     * machine-readable arguments does not make smaller arguments, it makes
     * invalid JSON, and every consumer answers that by running the ORIGINALS
     * the rewrite existed to replace — a silent fail-open wearing the hook's
     * authority.
     */
    public static function rewrite(string $hookName, string $output, int $replacedBytes): HookResult
    {
        $ceiling = max(ScriptHook::MIN_REWRITE_BYTES, $replacedBytes);
        $size = strlen($output);

        if ($size > $ceiling) {
            return HookResult::deny(sprintf(
                'Hook %s proposed a %d-byte rewrite of the tool input, over the %d-byte ceiling for '
                . 'this call (the larger of the %d bytes it replaces and %d); a rewrite cannot be '
                . 'truncated, so it is refused rather than cut.',
                $hookName,
                $size,
                $ceiling,
                $replacedBytes,
                ScriptHook::MIN_REWRITE_BYTES,
            ));
        }

        $decoded = json_decode($output, true);

        if (!is_array($decoded) || !str_starts_with(ltrim($output), '{')) {
            return HookResult::deny(
                "Hook {$hookName} asked to rewrite the tool input but did not print a JSON object",
            );
        }

        return HookResult::modify($output);
    }

    /**
     * The refusal an envelope that carried a known key but cannot be honoured
     * settles to — see {@see read()} for why it fails closed.
     */
    public function malformed(string $why): HookResult
    {
        return HookResult::deny(
            "Hook {$this->hookName} printed a JSON hook envelope that cannot be honoured ({$why}); "
            . 'a hook that meant to steer the call has not approved it.',
        );
    }

    /**
     * The question asked when an `ask` verdict arrived with no reason. A prompt
     * with an empty body is unanswerable, and falling back to allow/deny would
     * make silence mean something the hook never said.
     */
    private function defaultQuestion(): string
    {
        return $this->questionFallback !== null
            ? ($this->questionFallback)()
            : "Hook {$this->hookName} requires your approval";
    }
}
