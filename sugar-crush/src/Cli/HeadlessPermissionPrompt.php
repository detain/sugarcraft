<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Settles a {@see HookResult::ask()} for a run that has a CONSOLE but no TUI.
 * Two callers attach it, and the tty probe below decides opposite ways for
 * them: the `-p "<prompt>"` / `run "<prompt>"` one-shot path in
 * {@see NonInteractive}, which owns stdin and is answered at a real terminal;
 * and the background-session daemon
 * ({@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner::backend()}),
 * whose fd 0 is `/dev/null` from the spawn site, so it always takes the
 * refusal branch — attached there for the REFUSAL's text, not for a prompt
 * nobody would see.
 *
 * This is the caller {@see \SugarCraft\Crush\Backend\EngineBackend::withPermissionApprover()}
 * never had. Without one, {@see \SugarCraft\Crush\Runtime::settleAsk()} fails
 * every ASK closed — which is why a headless run under any mode that asks
 * (`default`, `accept-edits`, `auto`) refused writes instead of prompting for
 * them, and why the shipped default mode is `bypass-permissions`.
 *
 * THE SHAPE IS BLOCKING BECAUSE THE PATH IS SYNCHRONOUS. `NonInteractive::run()`
 * calls `Backend::complete()` on a plain stack — no ReactPHP loop, no
 * `pcntl_fork()` — so `Runtime`'s
 * `\Closure(ToolCall, HookResult): bool` contract, which resolves inline at
 * {@see \SugarCraft\Crush\Runtime::settleAsk()}, is the natural fit here rather
 * than a compromise. The TUI is a different problem and this class is
 * deliberately not the answer to it: `Chat`'s prompt is a `Deferred` settled by
 * a later `Msg`, and `EngineBackend::completeAsync()` runs the turn in a forked
 * child whose channel home is one-way, so neither can be served by a closure
 * that blocks on a file descriptor. See the known-gap entry in `README.md`.
 *
 * ## Two behaviours, decided by one probe
 *
 * `stream_isatty()` on the INPUT stream is the whole decision, because it is
 * the only thing that distinguishes "a person is sitting here" from "this is a
 * pipe in CI":
 *
 *  - **A terminal** — write the question to STDERR, read the answer from
 *    STDIN, grant only on an explicit `y`/`yes`.
 *  - **Not a terminal** — do NOT read, and refuse with a message that names
 *    the tool, the mode, and what to change. Blocking forever on a descriptor
 *    nobody will ever type into is strictly worse than a clean refusal for the
 *    caller this path exists for: one whose entire view of the run is stdout
 *    plus an exit code.
 *
 * Reading would ALMOST CERTAINLY not even block in that case, which is the
 * second reason the probe is the right gate rather than merely a prudent one:
 * {@see NonInteractive::readStdinIfPiped()} has already consumed a non-tty
 * stdin as prompt context before `complete()` is called, so an `fgets()` here
 * would usually return `false` immediately and the run would refuse anyway —
 * silently, and after the model had already been billed for the turn.
 *
 * "Usually" rather than "always", stated because the difference is measurable:
 * that method reads `MAX_STDIN_BYTES + 1` (10MB) and truncates, so a pipe
 * carrying more than 10MB is left NOT at EOF and an `fgets()` here would
 * return a line of leftover prompt text — read as an answer, and swallowed
 * from a stream whose owner is the caller, not this class. That is a strictly
 * worse failure than the silent one, and it is the probe, not the drain, that
 * prevents it: the no-tty branch never reads at all.
 *
 * ## The question goes to STDERR, never stdout
 *
 * `--output-format json` promises exactly one JSON object on stdout
 * ({@see NonInteractive::format()}). A prompt printed there would corrupt that
 * for the one caller most likely to be parsing it. STDERR carries the
 * question, STDIN carries the answer, stdout stays the answer channel — so a
 * human running `sugarcrush -p ... --output-format json | jq .` in a terminal
 * still gets prompted, and still gets parseable output.
 *
 * ## Why none of the four go on the transcript seam (E155)
 *
 * THE QUESTION NOBODY HAD ASKED OF THIS CLASS. Rounds 42–45 walked
 * {@see Bootstrap}'s writes one at a time and moved sixteen of them onto
 * {@see Bootstrap::warnPermissionConfigInTranscript()}, on the rule "a warning
 * reaches the transcript iff it names something the session can no longer DO".
 * By that rule alone all four shapes below QUALIFY: three of them are refusals,
 * and a refused tool call is the plainest possible example of a thing the
 * session can no longer do. The four stay on stderr anyway, for two reasons
 * that are about MECHANISM rather than about the rule, and both were checked
 * against the tree rather than reasoned from the rule's wording.
 *
 * FIRST — THE HAZARD THE SEAM EXISTS FOR CANNOT ARISE HERE. What makes a
 * launch-time `fwrite(STDERR, …)` worth moving is that the interactive launch
 * opens the alternate screen about half a second later (MEASURED at 0.47s on a
 * real pty, recorded on that method) and paints over it. This class is never
 * attached on a path that opens it. VERIFIED at the call sites rather than
 * assumed: {@see Bootstrap::backend()} and {@see Bootstrap::backendFor()} take
 * `$consolePermissionPrompt` defaulting to FALSE, exactly four callers in
 * `src/` pass `true`, and all four are in {@see NonInteractive} and
 * {@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner} — neither of
 * which takes the terminal. {@see Bootstrap::chat()}, the interactive path,
 * takes the default. {@see \SugarCraft\Crush\Tests\Cli\HeadlessPermissionPromptAttachmentTest}
 * pins that roster, because it is the fact this whole paragraph rests on and
 * the day it changes is the day the decision has to be made again.
 *
 * SECOND — THE SEAM IS NOT MERELY WRONG FOR THESE, IT IS UNREACHABLE.
 * `warnPermissionConfigInTranscript()` appends to a static list that
 * {@see Bootstrap::chat()} drains into `Chat::withLaunchNotices()` once, at
 * construction. These four fire from inside
 * {@see \SugarCraft\Crush\Runtime::settleAsk()}, mid-turn — after any drain
 * that was going to happen has happened, and on the `-p` path in a process that
 * never builds a `Chat` at all. A row recorded there would go into a static
 * array nobody reads. So "route it onto the seam" is not a deferred improvement
 * for this class; it is a different feature (a MID-SESSION notice sink), and it
 * is recorded as one.
 *
 * THE FOUR, and what each is:
 *
 *  1. {@see question()} — the prompt. Interactive branch only, and it is not a
 *     diagnostic at all: it is the interaction. It must be on the same console
 *     the answer is typed at, and stdout is spoken for.
 *  2. {@see refusal()} — the no-tty refusal. Carries the two remedies, and its
 *     reader is by construction someone reading a log rather than a screen.
 *  3. "stdin ended before the question was answered" — EOF mid-prompt.
 *  4. "refused <tool>" — an explicit non-affirmative answer, which is the one
 *     of the four the user already knows about, having just typed it.
 *
 * WHAT IS GENUINELY MISSING, and it is not a channel question: 2, 3 and 4 are
 * REFUSALS that never enter the `--output-format json` document. This class's
 * own docblock names the caller "whose entire view of the run is stdout plus an
 * exit code", and that caller sees a turn that completed with a tool quietly
 * not run. That is a gap in {@see NonInteractive::format()}, not in the routing
 * of these lines, and it is the same constraint Phase 9 step 1 has — a headless
 * user still has to receive a refusal. Recorded rather than half-done here.
 */
final class HeadlessPermissionPrompt
{
    /**
     * The only answers that grant. Matched exactly, after `trim()` +
     * `strtolower()` — NOT a prefix test: `n` must never be swallowed by a
     * `str_starts_with($answer, 'y')`-style check, and neither must a stray
     * `yes, but not that one`. Anything not on this list, including an empty
     * line and EOF, refuses.
     */
    private const AFFIRMATIVE = ['y', 'yes'];

    /**
     * Cap on the rendered argument blob, so one tool call carrying a whole
     * file's contents cannot scroll the question itself off the screen.
     *
     * KNOWN COST, stated rather than hidden: past this size the approver is
     * answering about a call it has not seen all of. The truncation says so in
     * as many words, and it is the reason the cap is generous rather than
     * tidy.
     */
    private const MAX_RENDERED_ARGUMENT_BYTES = 4096;

    /** @var resource */
    private $in;

    /** @var resource */
    private $err;

    /**
     * @param PermissionMode $mode The mode this launch resolved, named in both
     *        messages. A gate ASK already names it ({@see
     *        \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook::execute()}),
     *        but a custom hook's `ask()` does not, and the remedy line needs
     *        it either way.
     * @param resource|null $in  Answer stream; defaults to `STDIN`.
     * @param resource|null $err Question stream; defaults to `STDERR`.
     * @param bool|null $interactive Overrides the `stream_isatty()` probe.
     *        Null — the production value — probes $in.
     *
     *        This exists because no in-memory stream can EVER be a tty:
     *        `stream_isatty()` on `php://memory` or `php://temp` is false by
     *        construction, so without an override the prompting half of this
     *        class could not be exercised by any test at all, and shipping the
     *        branch that grants permission untested is the worse trade. It is
     *        also honest wiring for a caller that already knows its own
     *        situation.
     */
    public function __construct(
        private readonly PermissionMode $mode,
        $in = null,
        $err = null,
        private readonly ?bool $interactive = null,
    ) {
        $this->in = $in ?? \STDIN;
        $this->err = $err ?? \STDERR;
    }

    /**
     * This prompt as the `\Closure(ToolCall, HookResult): bool` that
     * {@see \SugarCraft\Crush\Backend\EngineBackend::withPermissionApprover()}
     * takes.
     */
    public function approver(): \Closure
    {
        return \Closure::fromCallable($this);
    }

    /**
     * Put one ASK to the console and return whether it was granted.
     *
     * The `bool` return type is load-bearing under `strict_types`: {@see
     * \SugarCraft\Crush\Runtime::settleAsk()} settles on `=== true`, never a
     * truthy cast, precisely because every
     * {@see \SugarCraft\Crush\Permissions\PermissionReply} case is a truthy
     * object. Declaring `bool` here means this class cannot be the one that
     * hands it something truthy-but-not-true.
     *
     * @param ToolCall $call The call as it will ACTUALLY RUN. `Runtime` has
     *        already applied any rewrite the ASK carries
     *        ({@see \SugarCraft\Crush\Runtime::asAsked()}) before calling
     *        here, so `$call->arguments()` is what gets executed — which is
     *        why this method renders those and never
     *        `$ask->rewrittenArgs()` or some earlier copy. Showing the
     *        original would put one command in front of the user and run
     *        another.
     */
    public function __invoke(ToolCall $call, HookResult $ask): bool
    {
        if (!$this->isInteractive()) {
            $this->write($this->refusal($call, $ask));

            return false;
        }

        $this->write($this->question($call, $ask));

        $line = \fgets($this->in);
        if ($line === false) {
            $this->write("sugarcrush: stdin ended before the question was answered — refusing {$call->name()}.\n");

            return false;
        }

        if (\in_array(\strtolower(\trim($line)), self::AFFIRMATIVE, true)) {
            return true;
        }

        $this->write("sugarcrush: refused {$call->name()}.\n");

        return false;
    }

    /**
     * Whether there is a person to ask. See the class docblock: this single
     * probe picks between the two behaviours.
     */
    private function isInteractive(): bool
    {
        if ($this->interactive !== null) {
            return $this->interactive;
        }

        return \is_resource($this->in) && \stream_isatty($this->in);
    }

    private function question(ToolCall $call, HookResult $ask): string
    {
        return "\nsugarcrush: a tool call needs your permission.\n"
            . '  tool: ' . $call->name() . "\n"
            . '  args: ' . $this->renderArguments($call) . "\n"
            . '  why:  ' . $this->oneLine($ask->message) . "\n"
            . '  mode: ' . $this->mode->value . "\n"
            . 'Run it? [y/N] ';
    }

    /**
     * The no-tty refusal. Every fact a reader needs to get unstuck is in here,
     * because stderr is the only surface this run has: WHAT was refused, WHY
     * nothing could answer, and the two things that change the outcome.
     */
    private function refusal(ToolCall $call, HookResult $ask): string
    {
        return "sugarcrush: a tool call needs your permission, and stdin is not a terminal,"
            . " so there is nobody to ask — refusing it.\n"
            . '  tool: ' . $call->name() . "\n"
            . '  args: ' . $this->renderArguments($call) . "\n"
            . '  why:  ' . $this->oneLine($ask->message) . "\n"
            . '  mode: ' . $this->mode->value . "\n"
            . "  Run this from a terminal to be prompted, or give the run a policy that decides\n"
            . "  without asking: --permission-mode <mode> (bypass-permissions runs everything),\n"
            . '  or a permissionRules entry for ' . $call->name() . " in .sugar-crush/config.json.\n";
    }

    /**
     * The call's arguments as one line of JSON.
     *
     * No extra control-character scrubbing, and that is a measurement rather
     * than an oversight: `json_encode()` escapes every C0 byte inside a string
     * as `\uXXXX`, so a model-authored argument carrying a raw `ESC[` cannot
     * emit a live CSI sequence from here and repaint the question it is being
     * asked about. `JSON_INVALID_UTF8_SUBSTITUTE` covers the other way this
     * fails — bytes PHP will not accept as UTF-8, which would otherwise make
     * `json_encode()` return `false` and blank the one field that matters.
     */
    private function renderArguments(ToolCall $call): string
    {
        $json = \json_encode(
            $call->arguments(),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($json === false) {
            return '<arguments could not be rendered>';
        }

        $length = \strlen($json);
        if ($length <= self::MAX_RENDERED_ARGUMENT_BYTES) {
            return $json;
        }

        // `mb_strcut()`, never `substr()`. The cap is a BYTE budget and the
        // field is UTF-8, so a bare `substr()` splits whatever character
        // straddles byte 4096 — measured: an `é` beginning at byte 4095 leaves
        // the rendered blob failing `mb_check_encoding()`. That would make the
        // truncation the thing that breaks the very invariant
        // `JSON_INVALID_UTF8_SUBSTITUTE` is here to hold, two lines above.
        // `mb_strcut()` rounds DOWN to a character boundary, the same fix and
        // the same reason as {@see \SugarCraft\Crush\Context\MemoryBlock}'s
        // byte-budget clipping.
        $shown = \mb_strcut($json, 0, self::MAX_RENDERED_ARGUMENT_BYTES, 'UTF-8');

        // Counted off what is ACTUALLY shown rather than off the constant:
        // rounding down to a boundary can leave the shown half up to three
        // bytes short, and shown + hidden has to equal the total or the number
        // is telling the approver something untrue about a call it is being
        // asked to allow.
        $hidden = $length - \strlen($shown);

        return $shown . " … (truncated — {$hidden} more bytes NOT shown)";
    }

    /**
     * Collapse a hook's multi-line `ask()` message onto the single line the
     * `why:` field is, so a long one cannot fake extra fields in the block
     * above the `[y/N]`.
     */
    private function oneLine(string $message): string
    {
        $collapsed = \preg_replace('/\s+/', ' ', $message);

        return \trim($collapsed ?? $message);
    }

    private function write(string $text): void
    {
        if (\is_resource($this->err)) {
            \fwrite($this->err, $text);
        }
    }
}
