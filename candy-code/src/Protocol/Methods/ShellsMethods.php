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

/**
 * `shells.*` (C-TOOL-3.e): the launch's background shell table over the wire —
 * list it, read one shell's output, stop one. These are the method forms of
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
 * read/stop with `shells_unavailable`.
 *
 * No spawn door: the Shell tool with `background: true` is the one place a
 * background shell is started (the plan's no-new-spawn-sites law), so there is
 * no `shells.start` beside these three.
 *
 * STOP is `write` scope and idempotent by the registry's own law: TERM to the
 * group, bounded grace, KILL to the same group, bounded confirmation — and a
 * second stop of a settled shell answers `found: true, wasRunning: false`,
 * while a foreign or unknown id answers `found: false`. None of those is an
 * error, so a client may retry freely.
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
