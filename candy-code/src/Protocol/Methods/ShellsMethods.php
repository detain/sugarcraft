<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Tools\BuiltIn\PtyRun;

/**
 * `shells.*` (C-TOOL-3.e): the launch's background shell table over the wire —
 * list it, read one shell's output, type into one (C-TOOL-6.l), stop one.
 * These are the method forms of
 * the TUI's `/shells` command ({@see \SugarCraft\Code\Host\Commands\ShellsCommand}),
 * so the web's Processes section and the terminal see exactly the same rows.
 *
 * OWNERSHIP rides the C-TOOL-3.c tags: every call names its `sessionId` and
 * that id is the owner the registry filters by — a session lists its own
 * shells plus the untagged ones, and a foreign shell id answers exactly as an
 * unknown one does (the registry's own missing-id shape, in both cases), so
 * the wire never lets one session probe another's table. The id is used
 * straight as the owner key; the call never opens a {@see CallContext::host()}:
 * the registry is a workspace-level table and a list/read/stop has no reason
 * to pay a session open or a per-session lock it would not use.
 *
 * The registry resolves through the same workspace service chain
 * `Bootstrap::backgroundShells()` feeds the Shell tool — never constructed
 * here; a second table would be a second reaper. A launch that registered
 * none answers `shells.list` with an empty page (like `bg.list`) and refuses
 * read/input/stop with `shells_unavailable`.
 *
 * No spawn door: the Shell tool with `background: true` is the one place a
 * background shell is started (the plan's no-new-spawn-sites law), so there is
 * no `shells.start` beside these arms.
 *
 * STOP is `write` scope and idempotent by the registry's own law: TERM to the
 * group, bounded grace, KILL to the same group, bounded confirmation — and a
 * second stop of a settled shell answers `found: true, wasRunning: false`,
 * while a foreign or unknown id answers `found: false`. None of those is an
 * error, so a client may retry freely.
 *
 * INPUT (C-TOOL-6.l) is `write` scope too, and the scope IS the gate: a
 * human pressed the keys on an authenticated browser, so no per-call
 * permission prompt exists on this path (the model's counterpart is
 * ShellSend, which does carry one). The bytes composed from `text` and the
 * named `key` ride the SAME registry `send()` the tool uses, tagged
 * `human` so the row's `humanInputs` counter grows, and the launch's log
 * keeps exactly one line per call — the shape of the keystroke, never its
 * content. This is a line-oriented door for a POLLING client: the browser
 * reads answers back through `shells.output` on its own timer; there is no
 * PTY-over-WebSocket stream here and this method does not make the page a
 * terminal emulator.
 *
 * The rows carry NO paths: `stdoutPath`/`stderrPath`/`exitPath` are private
 * filesystem and never cross the wire (the `bg.spawn` precedent line), and
 * `ageSeconds` is the only lifetime figure a caller needs.
 */
final class ShellsMethods
{
    /** The cursor forms `read()` decodes; both are advertised the same way here. */
    private const CURSOR_DIGITS = '/^\d+$/';

    private const CURSOR_PAIR = '/^\d+\.\d+$/';

    /**
     * The literal-`text` ceiling — the registry's own send cap
     * ({@see BackgroundShellRegistry::SEND_MAX_BYTES}). A composed send of
     * text plus a key token can still cross it; that refusal answers
     * `text_too_long` at the wire too.
     */
    public const MAX_TEXT_BYTES = 4096;

    /**
     * The named keys `shells.input` accepts: the subset of
     * {@see PtyRun::KEY_TOKENS} a polling line-oriented browser needs —
     * line completion, cancel, suspend, EOF, edit, arrows, tab. The rest of
     * the PtyRun grammar (Home, PageUp, the C-x letter codes) belongs to a
     * real terminal emulator and is refused by the schema enum.
     *
     * @var list<string>
     */
    public const INPUT_KEYS = ['Enter', 'Esc', 'Tab', 'Backspace', 'Up', 'Down', 'Left', 'Right', 'C-c', 'C-d', 'C-z'];

    /** The one audit line every input call leaves — facts only, never bytes. */
    private const AUDIT_FORMAT = 'shells.input: session=%s shell=%s client=%s principal=%s bytes=%d';

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new(
            'shells.list',
            Scope::Read,
            'The background shells this session may see — its own plus the untagged ones — without their output.',
            self::list(...),
        ));
        $registry->add(MethodSpec::new(
            'shells.output',
            Scope::Read,
            'One windowed read of a background shell\'s output from a cursor.',
            self::output(...),
        ));
        $registry->add(MethodSpec::new(
            'shells.input',
            Scope::Write,
            'Type into a running interactive background shell: literal bytes plus at most one named key, composed in that order and delivered as one human-origin send; the answer carries the cursor a following shells.output read threads from. This is a line-oriented door for a polling client — not a PTY stream over the socket, and it does not make the browser a terminal emulator.',
            self::input(...),
            true,
        ));
        $registry->add(MethodSpec::new(
            'shells.stop',
            Scope::Write,
            'Stop a background shell through the launch\'s kill ladder; a settled or foreign id is a no-op answer.',
            self::stop(...),
            true,
        ));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $registry = self::registry($call, soft: true);
        if ($registry === null) {
            return ['items' => []];
        }
        $owner = SessionMethods::sessionId($params);

        $items = [];
        foreach ($registry->ownedBy($owner) as $id) {
            $row = $registry->status($id, $owner);
            if ($row === null) {
                // The id left the table between the roster read and the
                // status read — the same silence `/shells list` keeps.
                continue;
            }
            $items[] = self::row($id, $row);
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    private static function output(CallContext $call, Params $params): array
    {
        $registry = self::registry($call, soft: false);
        $owner = SessionMethods::sessionId($params);
        $shellId = $params->string('shellId', 128);
        $cursor = self::cursor($params);

        $read = $registry->read($shellId, $cursor, null, $owner);
        if ($read['ok'] !== true) {
            // Unknown id and foreign owner answer with the SAME string the
            // registry builds — this method cannot tell them apart, so
            // neither can the caller.
            throw RpcError::notFound((string) $read['error'], 'shell_not_found');
        }

        return [
            'shellId' => $shellId,
            'stdout' => (string) $read['stdout'],
            'stderr' => (string) $read['stderr'],
            'cursor' => (string) $read['cursor'],
            'skippedBytes' => (int) $read['skippedBytes'],
            'bytesAvailable' => (int) $read['bytesAvailable'],
            'state' => (string) $read['state'],
            'exitCode' => $read['exitCode'],
            'exitNote' => $read['exitNote'],
            'expired' => (bool) $read['expired'],
        ];
    }

    /**
     * The composed keystroke: `text` verbatim then the named key's token —
     * the same {@see PtyRun::KEY_TOKENS} table the tool grammar decodes, so
     * `{"text":"ls","key":"Enter"}` and a step in a PtyRun script press the
     * same bytes. Public for the pin per leg (the design's composition law).
     */
    public static function typeBytes(?string $text, ?string $key): string
    {
        $bytes = $text ?? '';
        if ($key === null) {
            return $bytes;
        }
        if (!\in_array($key, self::INPUT_KEYS, true)) {
            throw RpcError::invalidParams('invalid params: key is not a named key shells.input accepts', 'shell_input_key');
        }

        return $bytes . PtyRun::KEY_TOKENS[$key];
    }

    /** @return array<string, mixed> */
    private static function input(CallContext $call, Params $params): array
    {
        $owner = '';
        $shellId = '';
        $typed = 0;
        try {
            $registry = self::registry($call, soft: false);
            $owner = SessionMethods::sessionId($params);
            $shellId = $params->string('shellId', 128);

            $text = $params->optionalString('text', self::MAX_TEXT_BYTES);
            $key = $params->optionalString('key', 32);
            if ($text === null && $key === null) {
                throw RpcError::invalidParams('invalid params: shells.input needs text or key', 'shell_input_empty');
            }
            if ($text !== null && \str_contains($text, "\x00")) {
                throw RpcError::invalidParams('invalid params: text must not contain NUL bytes', 'shell_input_nul');
            }
            $bytes = self::typeBytes($text, $key);
            $typed = \strlen($bytes);

            $send = $registry->send($shellId, $bytes, 'human', $owner);
            if ($send['ok'] === true) {
                return ['accepted' => true, 'cursor' => (int) $send['cursor']];
            }

            throw self::refusal((string) $send['error']);
        } finally {
            self::audit($call, $owner, $shellId, $typed);
        }
    }

    /**
     * Map the registry's own refusal sentences onto wire errors. The
     * visibility law survives: an unknown id and a foreign one answer with
     * the SAME `shell_not_found` the registry wrote (like `shells.output`),
     * so typing cannot probe another session's table.
     */
    private static function refusal(string $error): RpcError
    {
        if (\str_starts_with($error, 'no background shell ')) {
            return RpcError::notFound($error, 'shell_not_found');
        }
        if (\str_contains($error, 'not an interactive (pty) shell')) {
            return RpcError::of(ErrorCode::Conflict, $error, 'shell_not_interactive');
        }
        if (\str_contains($error, 'has already finished')) {
            return RpcError::of(ErrorCode::Conflict, $error, 'shell_settled');
        }
        if (\str_contains($error, 'a send is capped at ')) {
            return RpcError::invalidParams($error, 'text_too_long');
        }
        if (\str_contains($error, 'input FIFO could not be opened') || \str_contains($error, 'is not reading input')) {
            // The 6.k relay-attach window: honest retry-after-poll material,
            // not a loss — ShellSend::relayReady() is the readiness rule.
            return RpcError::of(ErrorCode::Conflict, $error, 'shell_relay_unready');
        }

        return RpcError::of(ErrorCode::Conflict, $error, 'shell_input_refused');
    }

    /**
     * The audit line: one per input call, through {@see ServerContext::logLine()}.
     * Method, session, shell id, client id, principal, byte count — the
     * SHAPE of the keystroke only; what was typed never reaches the log.
     */
    private static function audit(CallContext $call, string $session, string $shellId, int $bytes): void
    {
        $call->server->logLine(\sprintf(
            self::AUDIT_FORMAT,
            self::auditField($session),
            self::auditField($shellId),
            self::auditField($call->client->id()),
            self::auditField($call->client->principal()),
            $bytes,
        ));
    }

    /** One physical line per audit field: control bytes out, long ids clipped. */
    private static function auditField(string $value): string
    {
        $clean = \str_replace(["\x00", "\r", "\n"], '?', $value);

        return \strlen($clean) > 128 ? \substr($clean, 0, 128) : $clean;
    }

    /** @return array<string, mixed> */
    private static function stop(CallContext $call, Params $params): array
    {
        $registry = self::registry($call, soft: false);
        $owner = SessionMethods::sessionId($params);
        $shellId = $params->string('shellId', 128);
        $result = $registry->stop($shellId, $owner);

        return [
            'shellId' => $shellId,
            'found' => (bool) $result['found'],
            'wasRunning' => (bool) $result['wasRunning'],
            'confirmed' => (bool) $result['confirmed'],
            'grouped' => (bool) $result['grouped'],
            'exitCode' => $result['exitCode'],
            'expired' => (bool) $result['expired'],
        ];
    }

    /**
     * The wire row: the state, the label, how it settled, and its age — no
     * pid (a workspace-local fact with no wire meaning) and no paths.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(string $id, array $row): array
    {
        return [
            'shellId' => $id,
            'label' => (string) $row['label'],
            'state' => (string) $row['state'],
            'exitCode' => $row['exitCode'],
            'exitNote' => $row['exitNote'] ?? null,
            'expired' => (bool) ($row['expired'] ?? false),
            'ageSeconds' => (float) ($row['ageSeconds'] ?? 0.0),
        ];
    }

    /**
     * The `cursor` param as `read()` takes it: a non-negative integer, the
     * `out.err` token a previous read answered, or 0 when absent. Anything
     * else is refused rather than silently decoded to a from-the-start read
     * (the `/shells output` command's own cursor door).
     */
    private static function cursor(Params $params): int|string
    {
        $raw = $params->raw('cursor');
        if ($raw === null) {
            return 0;
        }
        if (\is_int($raw)) {
            if ($raw < 0) {
                throw RpcError::invalidParams('invalid params: cursor is negative');
            }

            return $raw;
        }
        if (\is_string($raw) && (\preg_match(self::CURSOR_DIGITS, $raw) === 1 || \preg_match(self::CURSOR_PAIR, $raw) === 1)) {
            return $raw;
        }

        throw RpcError::invalidParams('invalid params: cursor must be a byte count or an "out.err" token');
    }

    /**
     * The workspace's registry. With `soft` the null answer is legal (only
     * `shells.list` takes that branch, answering an empty page like
     * `bg.list`); read/stop refuse loudly through it instead.
     */
    private static function registry(CallContext $call, bool $soft): ?BackgroundShellRegistry
    {
        $registry = $call->server->hub()->workspace()->service(BackgroundShellRegistry::class);
        if ($registry instanceof BackgroundShellRegistry) {
            return $registry;
        }
        if ($soft) {
            return null;
        }

        throw RpcError::of(ErrorCode::UnsupportedInServer, 'this launch registers no background shells', 'shells_unavailable');
    }
}
