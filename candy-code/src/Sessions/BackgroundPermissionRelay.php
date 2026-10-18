<?php

declare(strict_types=1);

namespace SugarCraft\Code\Sessions;

use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Cli\HeadlessPermissionPrompt;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Support\ToolIpcFiles;
use SugarCraft\Code\Tools\ToolCall;

/**
 * ISS-004: the permission approver of a daemon spawned by `candy-code serve`.
 *
 * A `/bg` daemon has no terminal, so its gate's questions used to die at
 * {@see HeadlessPermissionPrompt}'s no-tty arm: note, refuse, move on. A
 * server, however, has clients — and this class turns the same question into
 * a line the server can broadcast as `permission.asked` and a human can
 * answer with `permission.respond`. The answer rides back on a sibling file;
 * the daemon waits for it up to the server's `server.askTimeoutSeconds`
 * semantics (0 waits forever, exactly as an open session's question does).
 *
 * ONE PAIR OF FILES PER SESSION, DERIVED FROM THE BUFFER PATH. The daemon
 * appends its questions to `<session>.ask`; the server appends the human's
 * answers to `<session>.answer`. Both live in the supervisor's 0700 per-spawn
 * directory, so the traversal they need (daemon writes, server reads, server
 * writes, daemon reads) is exactly the traversal the buffer and token already
 * require. Append-only lines, one JSON object each — the same record shape
 * the buffer uses — so a half-written line from a killed writer can only ever
 * cost that one question, which falls through to the legacy refusal.
 *
 * THE ANSWER IS ONCE-STRENGTH, DELIBERATELY. The daemon's gate sees a plain
 * bool grant for THIS call; there is no channel to grow a remembered grant
 * inside a process that resolves its mode from the launch config. A client
 * that answers `always` gets `once` semantics (the event names
 * `["once","reject"]` as its options, so honest clients never offer more),
 * and the strike/breaker counters inside the daemon are untouched by an
 * answered question — it was a real answer.
 *
 * TIMEOUT AND NO-CHANNEL FALL THROUGH TO THE LEGACY APPROVER: constructing a
 * fresh {@see HeadlessPermissionPrompt} with the same mode reproduces, byte
 * for byte, what every daemon did before this class existed — the E375
 * unattended note and the recorded refusal included. A relay that cannot
 * reach a server must never be WORSE than no relay at all.
 *
 * BYPASS KEEPS ITS MEANING (6bad89ebe, the owner's standing yes). A daemon
 * born in an admitted bypass mode still auto-grants the gate's asks without
 * bothering anyone; only a rostered hook question ({@see
 * HeadlessPermissionPrompt::demandsUnattendedAnswer()}) relays, and only
 * because its author promised it would be asked every time.
 */
final class BackgroundPermissionRelay
{
    /** Suffix of the question file, swapped in for the buffer's. */
    public const ASK_SUFFIX = '.ask';

    /** Suffix of the answer file, written by the server, read by the daemon. */
    public const ANSWER_SUFFIX = '.answer';

    /** How long the daemon sleeps between peeks at the answer file. */
    public const POLL_MICROSECONDS = 200_000;

    private const REPLY_ONCE = 'once';
    private const REPLY_ALWAYS = 'always';
    private const REPLY_REJECT = 'reject';

    /**
     * @param \Closure(ToolCall, HookResult): bool $legacyApprover what every
     *        daemon did before relaying existed — the no-tty refusal with its
     *        E375 note. Injectable so a test can watch the fall-through fire
     *        without a stderr to write at.
     * @param \Closure(): void $sleeper one poll pause, injectable for tests.
     * @param \Closure(): float $monotonic the deadline clock, injectable.
     */
    private readonly \Closure $legacyApprover;

    private readonly \Closure $sleeper;

    private readonly \Closure $monotonic;

    /** @param \Closure(ToolCall, HookResult): bool|null $legacyApprover null runs the console refusal */
    public function __construct(
        public readonly PermissionMode $mode,
        public readonly string $askPath,
        public readonly string $answerPath,
        public readonly float $timeoutSeconds,
        ?\Closure $legacyApprover = null,
        ?\Closure $sleeper = null,
        ?\Closure $monotonic = null,
    ) {
        if ($timeoutSeconds < 0.0) {
            // Fail loud, not stuck: "no relay" is the CALLER's door (see
            // BackgroundSessionRunner::withPermissionRelay), never a window of
            // its own — a negative here would silently wait forever past its
            // deadline math and hang the daemon's turn.
            throw new \InvalidArgumentException('a relay window is 0.0 (answer or never) or a positive number of seconds');
        }
        
        $this->legacyApprover = $legacyApprover ?? (new HeadlessPermissionPrompt($mode))->approver();
        $this->sleeper = $sleeper ?? static function (): void {
            \usleep(self::POLL_MICROSECONDS);
        };
        $this->monotonic = $monotonic ?? static fn (): float => \hrtime(true) / 1e9;
    }

    /**
     * The relay channel of a session buffer: the `.ask` / `.answer` pair a
     * daemon and its server share, or null when the path is not a session
     * buffer (a `.buffer.log`, a test path, anything else) and there is
     * nothing to derive from.
     *
     * ONE MAPPING, BOTH ENDS. The supervisor and the runner call this instead
     * of each spelling `str_replace('.buffer', ...)`, so a renamed suffix
     * moves the writer and the reader in the same commit or in neither.
     *
     * @return array{askPath: string, answerPath: string}|null
     */
    public static function pathsFor(string $bufferPath): ?array
    {
        if (!str_ends_with($bufferPath, BackgroundSupervisor::IPC_FILE_BUFFER_SUFFIX)) {
            return null;
        }
        $stem = substr($bufferPath, 0, -strlen(BackgroundSupervisor::IPC_FILE_BUFFER_SUFFIX));

        return ['askPath' => $stem . self::ASK_SUFFIX, 'answerPath' => $stem . self::ANSWER_SUFFIX];
    }

    /**
     * The approver-shaped closure Runtime settles on (`=== true` grants).
     */
    public function approver(): \Closure
    {
        return \Closure::fromCallable($this);
    }

    /**
     * Put one question to the server's clients and return whether it was
     * granted. The bool return type is load-bearing under `strict_types` for
     * the same reason {@see HeadlessPermissionPrompt::__invoke()}'s is.
     */
    public function __invoke(ToolCall $call, HookResult $ask): bool
    {
        if ($this->mode->isBypass() && !HeadlessPermissionPrompt::demandsUnattendedAnswer($ask)) {
            return true;
        }

        $askId = PendingAsk::askId($call->id(), $call->name(), $call->arguments());
        if (!$this->writeAsk($askId, $call, $ask)) {
            return ($this->legacyApprover)($call, $ask);
        }

        return match ($this->awaitAnswer($askId)) {
            self::REPLY_ONCE, self::REPLY_ALWAYS => true,
            self::REPLY_REJECT => false,
            // No answer inside the window: the question stands as unattended,
            // and the legacy approver records exactly that.
            default => ($this->legacyApprover)($call, $ask),
        };
    }

    /**
     * Append this question as one JSON line. A first write also creates the
     * answer file (0600 through the house narrowed write) so the server's
     * append never races its own creation.
     *
     * A failed write means the channel is not there — the daemon was born
     * from a `serve` whose config named the timeout but whose supervisor
     * directory was since cleaned. Fail soft into the legacy refusal; the
     * question was never put, so nothing waits.
     */
    private function writeAsk(string $askId, ToolCall $call, HookResult $ask): bool
    {
        if (!is_dir(dirname($this->askPath))) {
            return false;
        }
        if (!is_file($this->answerPath) && !ToolIpcFiles::write($this->answerPath, '')) {
            return false;
        }

        $line = json_encode([
            'askId' => $askId,
            'toolCallId' => $call->id(),
            'tool' => $call->name(),
            'arguments' => $call->arguments() === [] ? new \stdClass() : $call->arguments(),
            'reason' => $ask->message,
            'source' => PendingAsk::source($call, $ask),
            'mode' => $this->mode->value,
            'options' => [self::REPLY_ONCE, self::REPLY_REJECT],
            'requestedAt' => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return false;
        }

        return @file_put_contents($this->askPath, $line . "\n", FILE_APPEND) !== false;
    }

    /**
     * The reply word the server appended for $askId, or null when the window
     * closed first. Lines that name another question (an earlier call's
     * answer, a foreign write) are skipped; malformed lines are unread and
     * untrusted, which is the same fail-soft the buffer reader runs. The
     * LAST line for the id wins — the file is an append-only record, and
     * "newest row is current state" is the law every reader of it already
     * follows ({@see BackgroundSupervisor::permissionAsks()} deduped the
     * same way).
     */
    private function awaitAnswer(string $askId): ?string
    {
        $deadline = $this->timeoutSeconds > 0.0
            ? ($this->monotonic)() + $this->timeoutSeconds
            : null;

        do {
            ($this->sleeper)();
            $reply = $this->latestAnswerFor($askId);
            if ($reply !== null) {
                return $reply;
            }
            if ($deadline !== null && ($this->monotonic)() >= $deadline) {
                return null;
            }
        } while (true);
    }

    private function latestAnswerFor(string $askId): ?string
    {
        $text = @file_get_contents($this->answerPath);
        if (!is_string($text) || $text === '') {
            return null;
        }
        $reply = null;
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row) || ($row['askId'] ?? null) !== $askId) {
                continue;
            }
            $candidate = $row['reply'] ?? null;
            if (is_string($candidate) && in_array($candidate, [self::REPLY_ONCE, self::REPLY_ALWAYS, self::REPLY_REJECT], true)) {
                $reply = $candidate;
            }
        }

        return $reply;
    }
}
