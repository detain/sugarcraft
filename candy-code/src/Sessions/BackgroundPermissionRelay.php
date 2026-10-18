<?php

declare(strict_types=1);

namespace SugarCraft\Code\Sessions;

use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Cli\HeadlessPermissionPrompt;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Permissions\PermissionMode;
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
 * ONE PAIR OF FILES PER SESSION, THE ANSWER PATH MINTED BY THE SERVER. The
 * daemon appends its questions to `<session>.ask`; the server answers into a
 * file whose FULL PATH it minted at spawn — `<session>.answer-<nonce>`,
 * pre-created empty by the server itself under the narrowed 0600 write. The
 * daemon never creates the answer file and the path carries a 128-bit nonce,
 * so a process on the daemon's side of the channel cannot learn the answer
 * location by deriving it from the public buffer name, cannot pre-plant it,
 * and cannot answer at any path the server did not mint: content written at
 * an unminted path (the old fixed `<session>.answer`, or anything else) is
 * simply a file this relay never opens. Both files live in the supervisor's
 * 0700 per-spawn directory, so the traversal they need (daemon writes,
 * server reads, server writes, daemon reads) is exactly the traversal the
 * buffer and token already require. Append-only lines, one JSON object
 * each — the same record shape the buffer uses — so a half-written line from
 * a killed writer can only ever cost that one question, which falls through
 * to the legacy refusal.
 *
 * EVERY ASK CARRIES A FRESH TOKEN, AND THE ANSWER MUST ECHO IT (F2). The
 * askId is a content hash, so an identical replayed call asks the same id
 * again; an answer row from the earlier asking would otherwise settle the
 * replay instantly (the last-line-wins law turns staleness into a grant).
 * The daemon therefore draws a per-question nonce into each ask line, the
 * server echoes it back in the matching answer row, and this reader accepts
 * a row only when id AND token both name the question it is waiting on. A
 * tokenless row — the shape a pre-token writer leaves — matches only a
 * tokenless ask, which is how a daemon adopted from an older spawn keeps
 * working and is nothing else's door.
 *
 * A QUESTION THAT TIMES OUT IS SETTLED ON THE RECORD (F4). When the window
 * closes unanswered the daemon appends a `{"askId":…,"settled":"timeout"}`
 * line to the ask file before falling through to the legacy refusal, so the
 * server's readers know the question is dead: it leaves `permission.pending`
 * and a late answer to it reports unknown rather than appending a row
 * nobody polls.
 *
 * THE ANSWER IS ONCE-STRENGTH, DELIBERATELY. The daemon's gate sees a plain
 * bool grant for THIS call; there is no channel to grow a remembered grant
 * inside a process that resolves its mode from the launch config. A client
 * that answers `always` gets `once` semantics (the event names
 * `["once","reject"]` as its options, so honest clients never offer more),
 * and the strike/breaker counters inside the daemon are untouched by an
 * answered question — it was a real answer. Since F5 the coercion is not
 * merely semantic: the server writes and echoes `once` for a relayed
 * `always`, so the settled row, the `permission.settled` event and the
 * `permission.respond` result all tell the strength that was applied. This
 * reader still accepts an `always` row — an older writer's file must keep
 * settling its questions.
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

    /** Suffix of the answer file (the pre-F1 unminted shape), written by the server, read by the daemon. */
    public const ANSWER_SUFFIX = '.answer';

    /** Infix of a server-minted answer name: `<stem>.answer-<nonce>`. */
    public const ANSWER_MINT_INFIX = '.answer-';

    /** Hex characters of the minted nonce (16 random bytes). */
    public const ANSWER_NONCE_HEX = 32;

    /** How long the daemon sleeps between peeks at the answer file. */
    public const POLL_MICROSECONDS = 200_000;

    /** The ask-file line word closing a question the window expired on (F4). */
    public const SETTLED_TIMEOUT = 'timeout';

    /** Hex characters of a per-ask token (F2); the askId hash is 16, the token is longer so the two shapes are distinct. */
    public const ASK_TOKEN_HEX = 32;

    public const REPLY_ONCE = 'once';
    public const REPLY_ALWAYS = 'always';
    public const REPLY_REJECT = 'reject';

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
     * Mint a nonce-named answer path for a session buffer (F1): the name the
     * SERVER pre-creates at spawn and answers into, handed to the daemon as
     * a path the daemon could not derive. Null for a path that is not a
     * session buffer, for the same reason {@see pathsFor()} returns null.
     */
    public static function mintAnswerPath(string $bufferPath): ?string
    {
        $legacy = self::pathsFor($bufferPath);
        if ($legacy === null) {
            return null;
        }

        return substr($legacy['answerPath'], 0, -strlen(self::ANSWER_SUFFIX))
            . self::ANSWER_MINT_INFIX
            . bin2hex(random_bytes(intdiv(self::ANSWER_NONCE_HEX, 2)));
    }

    /**
     * Whether $candidate is a minted answer path belonging to $bufferPath:
     * the buffer's own directory, the session's own stem, and a hex nonce
     * of exactly {@see ANSWER_NONCE_HEX} characters. The daemon accepts the
     * path from its spawn config only through this door, and the supervisor
     * vets an adopted index record through it, so neither trusts a foreign
     * or a malformed location for its reads or its writes.
     */
    public static function isMintedAnswerPath(string $bufferPath, string $candidate): bool
    {
        $legacy = self::pathsFor($bufferPath);
        if ($legacy === null || dirname($candidate) !== dirname($legacy['answerPath'])) {
            return false;
        }
        $prefix = basename($legacy['answerPath'], self::ANSWER_SUFFIX) . self::ANSWER_MINT_INFIX;
        $name = basename($candidate);

        return str_starts_with($name, $prefix)
            && preg_match('/\A[0-9a-f]{' . self::ANSWER_NONCE_HEX . '}\z/', substr($name, strlen($prefix))) === 1;
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
        // F2: fresh per question, so the answer must have seen THIS asking.
        // The askId is a content hash and answers are append-only rows, so
        // without it a replayed identical call would settle off the stale
        // answer of its earlier self.
        $askToken = bin2hex(random_bytes(intdiv(self::ASK_TOKEN_HEX, 2)));
        if (!$this->writeAsk($askId, $askToken, $call, $ask)) {
            return ($this->legacyApprover)($call, $ask);
        }

        return match ($this->awaitAnswer($askId, $askToken)) {
            self::REPLY_ONCE, self::REPLY_ALWAYS => true,
            self::REPLY_REJECT => false,
            // No answer inside the window: the question closes on the record
            // (F4) and stands as unattended, and the legacy approver records
            // exactly that.
            default => $this->settleUnanswered($askId, $call, $ask),
        };
    }

    /**
     * Append this question as one JSON line carrying its token. The daemon
     * never creates the answer file — that path is the server's to mint and
     * pre-create (F1) — so the only thing a channel-less daemon leaves
     * behind is its own unread question.
     *
     * A failed write means the channel is not there — the daemon was born
     * from a `serve` whose config named the timeout but whose supervisor
     * directory was since cleaned. Fail soft into the legacy refusal; the
     * question was never put, so nothing waits.
     */
    private function writeAsk(string $askId, string $askToken, ToolCall $call, HookResult $ask): bool
    {
        if (!is_dir(dirname($this->askPath))) {
            return false;
        }

        $line = json_encode([
            'askId' => $askId,
            'askToken' => $askToken,
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
     * Close a question the window expired on (F4), then hand it to the
     * legacy approver. The settle line is the server's notice that its
     * readers should drop the question from `permission.pending`; it is
     * best-effort — a channel whose directory vanished has nobody left to
     * mislead — and the refusal the legacy approver records stands either way.
     */
    private function settleUnanswered(string $askId, ToolCall $call, HookResult $ask): bool
    {
        $line = json_encode([
            'askId' => $askId,
            'settled' => self::SETTLED_TIMEOUT,
            'settledAt' => date(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            @file_put_contents($this->askPath, $line . "\n", FILE_APPEND);
        }

        return ($this->legacyApprover)($call, $ask);
    }

    /**
     * Poll the server-minted answer file for this asking's reply, or null
     * when the window closed first (a zero window waits forever, exactly as
     * an open session's question does). {@see latestAnswerFor()} states the
     * match law.
     */
    private function awaitAnswer(string $askId, string $askToken): ?string
    {
        $deadline = $this->timeoutSeconds > 0.0
            ? ($this->monotonic)() + $this->timeoutSeconds
            : null;

        do {
            ($this->sleeper)();
            $reply = $this->latestAnswerFor($askId, $askToken);
            if ($reply !== null) {
                return $reply;
            }
            if ($deadline !== null && ($this->monotonic)() >= $deadline) {
                return null;
            }
        } while (true);
    }

    /**
     * The reply word the server appended for this asking, or null when no row
     * names it. "This asking" means BOTH id and token (F2): a row that does
     * not echo the token of the question being polled is another question's
     * answer — an earlier asking's, or a forgery's — and cannot settle it.
     * The tokenless spelling is kept exact (a tokenless row matches only a
     * tokenless ask) so a daemon adopted from a pre-token spawn still reads
     * the pre-token server answers it has always accepted, and nothing else's.
     * Lines that name another question, malformed lines, and rows whose reply
     * is not a known word are unread and untrusted — the same fail-soft the
     * buffer reader runs. The LAST matching line wins — the file is an
     * append-only record, and "newest row is current state" is the law every
     * reader of it already follows ({@see BackgroundSupervisor::permissionAsks()}
     * deduped the same way).
     */
    private function latestAnswerFor(string $askId, string $askToken): ?string
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
            $rowToken = $row['askToken'] ?? '';
            if (!is_string($rowToken) || $rowToken !== $askToken) {
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
