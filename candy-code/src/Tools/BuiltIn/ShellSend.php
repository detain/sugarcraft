<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Tools\AcceptsShellOwner;
use SugarCraft\Code\Tools\CarriesSessionState;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Types keystrokes into an interactive background shell started by
 * `Bash(run_in_background: true, interactive: true)` (C-TOOL-6.k — the
 * wiring half of the C-TOOL-6.j relay engine).
 *
 * THE KEYS RIDE THE SAME GRAMMAR AS `PtyRun` STEPS: {@see PtyRun::decode()}
 * turns `<Enter>`, `<C-c>`, `\x04` and plain text into the real wire bytes a
 * keyboard produces — never a glyph — because the relay hands those bytes to
 * a live pty. The decoded bytes are capped at
 * {@see BackgroundShellRegistry::SEND_MAX_BYTES} by the engine's own door;
 * the ceiling is checked on the DECODED bytes, so a template can never smuggle
 * more terminal input than the cap names.
 *
 * THE READ-BACK IS CURSOR-ANCHORED, NOT SLEPT-FOR-GUESSWORK: `send()` answers
 * where the shell's output stood BEFORE the write, so this tool waits its
 * declared `wait_ms` and reads from that cursor — the answer is exactly the
 * bytes the keystroke produced (plus any later ones until the read), never a
 * frame that had already been waiting. The cursor pairing is the engine's
 * own race-free design (C-TOOL-6.j); the display text is ANSI-stripped like
 * the terminal screen it came off, because terminal paint is noise to a text
 * model, and the raw stream stays reachable through {@see ShellOutput}.
 *
 * WAIT IS A CEILING ON THIS CALL'S PATIENCE, NEVER A KILL CLOCK (E646): the
 * shell outlives the wait whether or not it answered — nothing here signals,
 * stops or bounds the running program. Stopping is {@see ShellStop}'s job
 * and the lifetime wall is the engine's, both unchanged by this call.
 *
 * THE RELAY-START WINDOW IS A BOUNDED NIT, NOT A HANG (C-TOOL-6.j hand-off):
 * between the relay recording its pid and opening the input FIFO there is a
 * sub-millisecond window where a well-meant send is refused "the relay is not
 * reading input". This tool re-reads the door and retries within a small
 * fixed budget (ticks, ceiling named in {@see RELAY_ATTACH_BUDGET_MS}) and
 * then surfaces the engine's refusal unchanged — a dead relay still answers
 * dead, and no call waits on a promise the kernel has not made.
 *
 * WRITE permission class, and this is the roster argument the review asks
 * for: like {@see ShellStop} this tool is deliberately NOT on
 * {@see \SugarCraft\Code\Permissions\ShellSubjectTools}. That roster is the
 * list of tools whose SUBJECT IS A SHELL COMMAND LINE — text the permission
 * matchers parse with `Bash(rm *)`-style rules, segment splitting, and the
 * cross-binding proxy. ShellSend's `keys` are not a command line: they are
 * keystrokes into whatever program holds the terminal, which may itself be a
 * REPL, an editor, or a shell the row's original command never mentioned.
 * Membership would let `Allow Bash(git *)` grant typing `git` into any
 * session, and would let the auto classifier read a line of REPL input as if
 * it were the whole spawn policy — both over-grant. So the pair stays
 * {['Bash', 'PtyRun']}, and ShellSend is gated instead by the KEYSTROKE rule
 * — the same one that already forces every `PtyRun` step to be a question:
 * `PermissionGate::decide()` and the `auto` evaluator both treat a ShellSend
 * call as typing into a live terminal, so it asks every time in every
 * non-bypass mode, no session "always" covers it, and `auto` never allows it
 * nor resets its counters on it. An explicit `Allow ShellSend` rule answers
 * first — and because the rule names the tool and not a command line, that
 * one answer covers every shell and every key: it is a decision to let this
 * model type at all, which is exactly what it looks like. The `rm -rf /`
 * breaker still reaches typed input: the gate runs each DECODED LINE of the
 * keystrokes through the same word floor, so typing `rm -rf /` at a rescue
 * shell trips it even under `bypass-permissions`.
 *
 * SINCE C-TOOL-3.c a send answers only shells this session owns or that
 * carry no owner at all: a foreign session's id takes the SAME not-tracked
 * refusal an unknown id takes, so one launch cannot type into another's
 * terminal and the answer cannot be probed for existence.
 */
#[BuiltInTool(name: 'ShellSend', permission: ToolPermissionClass::Write, position: 47, gloss: 'type keystrokes into an interactive background shell started with Bash run_in_background and interactive')]
final readonly class ShellSend implements Tool, AcceptsShellOwner, BuildsFromCatalog, TakesToolCallId, CarriesSessionState
{
    public const NAME = 'ShellSend';

    /** Ceiling on the WAIT this call takes for the screen to answer (ms). */
    public const MAX_WAIT_MS = 10_000;

    /** Default wait when the caller names none (ms). */
    public const DEFAULT_WAIT_MS = 500;

    /**
     * Budget for the relay-attach retry window (the C-TOOL-6.j hand-off NIT):
     * a send refused because the FIFO is not yet reading is re-tried at a
     * fixed tick until this much time has passed, then the refusal stands.
     */
    public const RELAY_ATTACH_BUDGET_MS = 2_000;

    /** Tick between relay-attach re-trials (ms). */
    private const RELAY_ATTACH_TICK_MS = 25;

    private function __construct(
        private ?BackgroundShellRegistry $registry = null,
        private ?string $shellOwner = null,
    ) {}

    /**
     * This tool scoping its sends to session $owner, registry kept — the
     * rebuild is total because both properties are constructor-promoted
     * (C-TOOL-3.c); {@see \SugarCraft\Code\Tools\AcceptsShellOwner} is the
     * seam the turn hands the session id over.
     */
    public function withShellOwner(?string $owner): self
    {
        return new self($this->registry, $owner);
    }

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->backgroundShells);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Type keystrokes into an interactive background shell — one started with Bash(run_in_background: true, interactive: true) — addressed by the shell_id Bash returned. Keys use the PtyRun step grammar: plain text plus <Enter>, <C-c>, <Up>, \\xHH and friends; "<Enter>" is how a line is submitted. Waits wait_ms for the screen to answer (default 500, max 10000 — a ceiling on this call\'s patience, never a kill: the shell outlives the wait), then returns the output that followed, ANSI-stripped. This is a live terminal: whatever program holds it receives the bytes, so a rule about command lines cannot speak for it and every send asks unless an explicit `Allow ShellSend` answers first. It never stops the shell — that is ShellStop.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shell_id' => [
                    'type' => 'string',
                    'description' => 'The id Bash returned when it started the interactive shell (sh-xxxxxxxx).',
                ],
                'keys' => [
                    'type' => 'string',
                    // The cap number is the engine's private SEND_MAX_BYTES
                    // (BackgroundShellRegistry); the schema restates it as a
                    // floor-safe sentence and ShellSendTest pins the literal
                    // against the constant by reflection, so a move of the
                    // engine ceiling reddens the test, not the wire text.
                    'description' => 'Text and key tokens to type, in the PtyRun step grammar (<Enter>, <C-c>, <Up>, \\xHH; unknown <tokens> stay literal). The decoded bytes are capped at 4096 by the engine; include <Enter> (or a \\n) to submit a line.',
                ],
                'wait_ms' => [
                    'type' => 'integer',
                    'description' => sprintf('Milliseconds to let the screen settle after typing before reading it back (default %d, max %d). Out-of-range values are clamped, never unbounded.', self::DEFAULT_WAIT_MS, self::MAX_WAIT_MS),
                    'minimum' => 0,
                    'maximum' => self::MAX_WAIT_MS,
                ],
            ],
            'required' => ['shell_id', 'keys'],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): ToolResult
    {
        $id = $args['shell_id'] ?? null;
        if (!\is_string($id) || \preg_match('/^sh-[0-9a-f]{8}$/', $id) !== 1) {
            return $this->refuse('shell_id must be an id of the form sh-xxxxxxxx exactly as Bash returned it');
        }
        if ($this->registry === null) {
            return $this->refuse('no background shell registry is attached to this launch');
        }
        $keys = $args['keys'] ?? null;
        if (!\is_string($keys) || $keys === '') {
            return $this->refuse('keys must be a non-empty string of text and key tokens (for example "ls<Enter>")');
        }
        $decoded = PtyRun::decode($keys);
        if ($decoded === '') {
            // An all-`<>`-less-empty template is a typing call that types
            // nothing; refusing beats writing zero bytes and waiting on them.
            return $this->refuse('keys decoded to nothing to type — the grammar only removes bytes through known tokens');
        }

        $waitMs = $args['wait_ms'] ?? self::DEFAULT_WAIT_MS;
        $waitMs = \is_int($waitMs) ? \max(0, \min(self::MAX_WAIT_MS, $waitMs)) : self::DEFAULT_WAIT_MS;

        // The door refusals the engine phrases generically get their named
        // wording here, from the row the visibility law already admits; the
        // authoritative write still runs through send(), which re-checks
        // every door again in its own order (a settle racing this read costs
        // nothing — send() answers settled).
        $row = $this->registry->status($id, $this->shellOwner);
        if ($row === null) {
            return $this->refuse('no background shell ' . $id . ' is tracked by this launch');
        }
        if (($row['interactive'] ?? false) !== true) {
            return $this->refuse('shell ' . $id . ' is not an interactive (pty) shell; start it with interactive: true; a plain background shell has no terminal to type into');
        }

        $answer = $this->sendBounded($id, $decoded);
        if ($answer['ok'] !== true) {
            return $this->refuse((string) $answer['error']);
        }

        if ($waitMs > 0) {
            \usleep($waitMs * 1000);
        }

        // The cursor `send()` answered is stdout's pre-write offset; threaded
        // as a digit string it decodes through the legacy combined arithmetic
        // the read docblock names — exact for an interactive row, whose pty
        // stream merges into stdout and whose stderr sink stays empty.
        $read = $this->registry->read($id, (string) $answer['cursor'], null, $this->shellOwner);
        if ($read['ok'] !== true) {
            return $this->refuse((string) $read['error']);
        }

        return new ToolResult(
            toolCallId: (string) ($args['id'] ?? ''),
            content: $this->paint($id, $decoded, $read),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSessionState(): array
    {
        return [BackgroundShellRegistry::SESSION_STATE_KEY => $this->registry?->toArray() ?? []];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mergeSessionState(array $state): void
    {
        $this->registry?->merge($state[BackgroundShellRegistry::SESSION_STATE_KEY] ?? null);
    }

    /**
     * Type once, and if the refusal is the relay-attach window rather than a
     * door, re-try within the bounded budget. Only the two relay-side shapes
     * are retried; every door refusal (unknown, non-interactive, settled,
     * oversize) returns at once — re-asking a settled shell is fiction.
     *
     * @return array{ok: bool, error?: string, cursor?: int}
     */
    private function sendBounded(string $id, string $decoded): array
    {
        $deadline = \microtime(true) + self::RELAY_ATTACH_BUDGET_MS / 1000;
        $answer = $this->typedSend($id, $decoded);
        while ($answer['ok'] !== true
            && \microtime(true) < $deadline
            && $this->isRelayAttachWindow((string) ($answer['error'] ?? ''))) {
            \usleep(self::RELAY_ATTACH_TICK_MS * 1000);
            $answer = $this->typedSend($id, $decoded);
        }

        return $answer;
    }

    /**
     * One send with the settled door re-checked BEFORE each re-trial, so a
     * shell that died mid-window answers "already finished" once, honestly,
     * instead of looping through fifo refusals against a corpse.
     *
     * @return array{ok: bool, error?: string, cursor?: int}
     */
    private function typedSend(string $id, string $decoded): array
    {
        $answer = $this->registry?->send($id, $decoded, 'model', $this->shellOwner) ?? ['ok' => false, 'error' => 'no background shell registry is attached to this launch'];
        if ($answer['ok'] === true || !$this->isRelayAttachWindow((string) ($answer['error'] ?? ''))) {
            return $answer;
        }
        $row = $this->registry?->status($id, $this->shellOwner);
        if ($row !== null && (string) ($row['state'] ?? '') !== 'running') {
            return ['ok' => false, 'error' => 'shell ' . $id . ' has already finished; start a new one to type again'];
        }

        return $answer;
    }

    private function isRelayAttachWindow(string $error): bool
    {
        return $error === 'the input FIFO could not be opened; the relay may have just died'
            || $error === 'the relay is not reading input; the send was refused';
    }

    /**
     * @param array<string, mixed> $read
     */
    private function paint(string $id, string $decoded, array $read): string
    {
        $stdout = \SugarCraft\Core\Util\Ansi::strip((string) $read['stdout']);
        $stderr = \SugarCraft\Core\Util\Ansi::strip((string) $read['stderr']);
        $body = $stdout;
        if ($stderr !== '') {
            $body .= ($body !== '' ? "\n" : '') . '[stderr]' . "\n" . $stderr;
        }
        if ($body === '') {
            $body = '(no output in the wait — the screen did not answer yet; read more with ShellOutput from the cursor below)';
        }

        $typed = \strlen($decoded);

        return sprintf("sent %d bytes to shell %s\n", $typed, $id) . $body . "\n"
            . sprintf('[cursor: %s]', (string) $read['cursor']);
    }

    private function refuse(string $reason): ToolResult
    {
        return new ToolResult(toolCallId: '', content: $reason, isError: true);
    }
}
