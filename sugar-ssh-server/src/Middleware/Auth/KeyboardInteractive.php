<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware\Auth;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Lang;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\StreamHelper;

/**
 * Challenge-response keyboard-interactive authentication.
 *
 * **Informational only — this middleware does NOT negotiate the
 * RFC 4256 wire format.** The real SSH_MSG_USERAUTH_INFO_REQUEST /
 * INFO_RESPONSE binary packets are completed inside sshd before a
 * ForceCommand process like this one ever runs (mirrors the honest
 * disclaimer on {@see \SugarCraft\SshServer\Middleware\AuthMethods} for
 * RFC 4252). What ships here is an app-line stdio simulation: it
 * writes plaintext prompt lines to the session's STDOUT and reads
 * newline-delimited answers from STDIN.
 *
 * This middleware:
 *
 *   1. Writes Name, Instruction, prompt count, and per-prompt
 *      prompt/echo-flag lines to STDOUT following the RFC 4256
 *      *shape* (as plain application lines, not SSH packets).
 *   2. Reads newline-delimited responses from STDIN until all
 *      prompts have been answered. The read blocks by contract —
 *      see {@see readResponses()} for the per-connection-process
 *      reasoning and the EOF guarantee.
 *   3. Optionally validates the responses via a callback. An EOF
 *      that leaves any prompt unanswered rejects the session before
 *      the validator runs; a short exchange never authenticates. So does
 *      an answer longer than {@see MAX_RESPONSE_BYTES} — the exchange is
 *      refused rather than slurped into memory without a bound. A validator
 *      that returns `false` rejects the session with a message on
 *      stderr; a validator that returns `true` (or no validator)
 *      passes control to `$next`.
 *
 * **Echo is a label, not terminal state (audit MAJOR 6).** `echo: false`
 * changes nothing about how the characters reach you: the terminal that
 * `sshd` allocated for this session is still in its own echo state, so a
 * password typed at a `echo: false` prompt is written onto the user's
 * screen and into their scrollback exactly like one at `echo: true`.
 * Because that is the opposite of what the flag name promises, the class
 * says the truth out loud at the point of typing: when any challenge
 * carries `echo: false`, one line is appended after the prompt list
 * warning that the terminal echoes what you type. Suppressing echo for
 * real means clearing `ECHO` on the caller's terminal; this library does
 * not do that, and the termios surface it depends on (sugar-pty's
 * {@see \SugarCraft\Pty\Contract\Termios}) offers only `makeRaw()`, which
 * additionally clears `ICANON` and `ISIG` — line editing and `Ctrl-C` —
 * where an authenticating prompt wants `ECHO` off and everything else
 * kept. That gap is upstream work, recorded in the library CALIBER notes;
 * do not treat the warning line as a substitute for hiding the input.
 * If a secret must not be visible, collect it over a channel you control
 * instead of this one.
 *
 * Line format (RFC-4256-shaped, plaintext). Challenges are emitted with
 * LF line endings only; a client may answer with CRLF, and each response
 * line is read whole and its trailing CR trimmed, so both shapes land as
 * the same value:
 *
 *     Name\n
 *     Instruction\n
 *     NumberOfPrompts\n
 *     Prompt1\n
 *     Echo1\n          <-- 'true' or 'false', a label only
 *     Prompt2\n
 *     Echo2\n          <-- 'true' or 'false', a label only
 *     ...
 *     [warning line]   <-- only when some challenge has echo: false
 *
 *     Response1\n
 *     Response2\n
 *     ...
 */
final class KeyboardInteractive implements Middleware
{
    /**
     * Longest answer this middleware will accept, in bytes (audit MAJOR 6).
     *
     * `fgets()` without a limit will happily read as much as a peer is
     * willing to write, and the exchange runs before any of the transport's
     * own bounds exist. A line that overruns this budget refuses the
     * session rather than growing an unbounded string.
     */
    public const MAX_RESPONSE_BYTES = 4096;

    /** @var list<array{prompt: string, echo: bool}> */
    private array $challenges;

    /** @var callable(list<string>): bool|null */
    private $validate;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stderr;

    /** RFC-4256 Name field (written as first line of challenge output) */
    private string $name;

    /** RFC-4256 Instruction field (written as second line of challenge output) */
    private string $instruction;

    /**
     * @param list<array{prompt: string, echo?: bool}|string> $challenges Prompt list; a bare
     *        string is the prompt with `echo: true` (audit MAJOR 6: shapes
     *        that used to be silently skipped at write time are refused
     *        here instead)
     * @param callable(list<string>): bool|null        $validate   Receives responses, returns true to accept
     * @param string                                   $name       RFC-4256 Name (e.g. "Login")
     * @param string                                   $instruction RFC-4256 Instruction
     * @param resource|null                           $stdout
     * @param resource|null                           $stdin
     * @param resource|null                           $stderr
     */
    public function __construct(
        array $challenges,
        ?callable $validate = null,
        string $name = '',
        string $instruction = '',
        $stdout = null,
        $stdin = null,
        $stderr = null,
    ) {
        $this->challenges = self::normaliseChallenges($challenges);
        $this->validate = $validate;
        $this->name = $name;
        $this->instruction = $instruction;
        // Fail-fast validation of injected streams happens at the
        // boundary: a bad stdin now throws here, not
        // deep inside the first fgets().
        $this->stdout = StreamHelper::openOrValidate($stdout, 'php://stdout');
        $this->stdin = StreamHelper::openOrValidate($stdin, 'php://stdin', 'r');
        $this->stderr = StreamHelper::openOrValidate($stderr);
    }

    /**
     * Accept the two shapes a caller can mean and refuse every other one.
     *
     * Reading `$challenge['prompt']` on an entry shaped differently used to
     * produce nothing at all: the count line promised N prompts, the loop
     * emitted fewer, and the exchange could not complete (audit MINOR 22).
     * A prompt list is operator-authored configuration, so a malformed
     * entry is a programming error and is raised at construction.
     *
     * @param array<array-key,mixed> $challenges
     * @return list<array{prompt: string, echo: bool}>
     */
    private static function normaliseChallenges(array $challenges): array
    {
        $normalised = [];
        foreach ($challenges as $index => $challenge) {
            if (\is_string($challenge)) {
                $normalised[] = ['prompt' => $challenge, 'echo' => true];
                continue;
            }
            if (\is_array($challenge) && isset($challenge['prompt']) && \is_string($challenge['prompt'])) {
                $normalised[] = [
                    'prompt' => $challenge['prompt'],
                    'echo'   => (bool) ($challenge['echo'] ?? true),
                ];
                continue;
            }
            throw new \InvalidArgumentException(
                'Challenge ' . $index . ' must be a prompt string or an array with a string'
                . ' "prompt" key; got ' . \get_debug_type($challenge) . '.'
            );
        }
        return $normalised;
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        $this->writeChallenges();
        $responses = $this->readResponses();

        if (\count($responses) !== \count($this->challenges)) {
            // EOF before the exchange finished: the client hung up mid-
            // prompt. An incomplete exchange is a failed authentication,
            // never a passed one — reject without ever
            // consulting a validator, which would face a short list.
            $session->markRefused();
            fwrite($this->stderr, Lang::t('keyboardinteractive.auth_failed') . "\n");
            return;
        }

        if ($this->validate !== null && !($this->validate)($responses)) {
            $session->markRefused();
            fwrite($this->stderr, Lang::t('keyboardinteractive.auth_failed') . "\n");
            return;
        }

        $derived = $ctx->withValue('auth.ki.responses', $responses);
        $next($derived, $session);
    }

    /**
     * Write challenges to stdout in the RFC 4256 *shape*.
     *
     * Emits: Name, Instruction, Count, then per-challenge Prompt and Echo
     * flag; when any challenge asks for hidden input, one more line saying
     * that this prompt cannot actually hide it (audit MAJOR 6).
     */
    private function writeChallenges(): void
    {
        $count = \count($this->challenges);
        $hidden = false;
        fwrite($this->stdout, $this->name . "\n");
        fwrite($this->stdout, $this->instruction . "\n");
        fwrite($this->stdout, (string) $count . "\n");
        foreach ($this->challenges as $challenge) {
            $echo = $challenge['echo'];
            $hidden = $hidden || !$echo;
            fwrite($this->stdout, $challenge['prompt'] . "\n");
            fwrite($this->stdout, ($echo ? 'true' : 'false') . "\n");
        }
        if ($hidden) {
            fwrite($this->stdout, Lang::t('keyboardinteractive.input_visible') . "\n");
        }
        fflush($this->stdout);
    }

    /**
     * Read exactly `$count` lines from stdin.
     *
     * **Blocking by contract.** This middleware runs in
     * the dedicated per-connection PHP process that `sshd` forks under
     * the ForceCommand model (see {@see \SugarCraft\SshServer\Server}), and
     * the transport walks the middleware chain synchronously *before*
     * any pump loop is started — there is no shared event loop this read
     * could stall. Waiting here has the semantics of an interactive
     * prompt at a login shell: it waits for a human to type.
     *
     * Two properties bound the wait:
     *   - a client disconnect surfaces as EOF on the stdio pipe, so
     *     `fgets()` returns false and this loop ends immediately;
     *     handle() then rejects the incomplete exchange;
     *   - an answer longer than {@see MAX_RESPONSE_BYTES} ends the exchange
     *     the same way, so a peer cannot make this read allocate without a
     *     ceiling.
     *
     * Nothing else is imposed here, and the honest statement of what that
     * means (audit MAJOR 6): a dead-but-connected peer that never sends a
     * newline and never disconnects holds this process open indefinitely.
     * `sshd`'s `LoginGraceTime` does NOT cover it — that timer belongs to
     * the pre-authentication exchange and has already expired by the time
     * the post-authentication `ForceCommand` child starts asking questions.
     * The Keepalive middleware is not a cure either: it writes a NUL into
     * the child's stdin when the pump idles, which produces no newline here
     * (see {@see \SugarCraft\SshServer\Middleware\Keepalive}). Callers that
     * need a deadline bound the stream they inject as `$stdin` — a
     * `stream_select()`-guarded wrapper, or `timeout(1)` around the entry
     * script — and the README's provisioning notes say so.
     *
     * @return list<string> lines read so far — fewer than the prompt
     *                      count only when stdin hit EOF mid-exchange
     */
    private function readResponses(): array
    {
        $responses = [];
        $count = \count($this->challenges);
        for ($i = 0; $i < $count; $i++) {
            // +2 so a full budget plus its newline still reads as a line,
            // and anything beyond that is detectable by length.
            $line = fgets($this->stdin, self::MAX_RESPONSE_BYTES + 2);
            if ($line === false) {
                break;
            }
            if (\strlen($line) > self::MAX_RESPONSE_BYTES && !\str_ends_with($line, "\n")) {
                // A peer writing past the budget is not typing an answer.
                // Refuse the exchange by returning short; handle() turns
                // that into the same auth_failed path as an EOF, and the
                // validator is never fed an oversized blob.
                break;
            }
            $responses[] = \rtrim($line, "\r\n");
        }
        return $responses;
    }
}
