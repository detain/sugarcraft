<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Host\Commands\Checkpoints;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tools\PathJail;
use SugarCraft\Code\Workspace\CheckpointDiff;
use SugarCraft\Code\Workspace\GitRunner;
use SugarCraft\Code\Workspace\WorkspaceCheckpointer;

/**
 * `files.*` (Appendix O §6.3): what changed in the workspace, and a file's
 * text — read-only, jailed to the project root ({@see PathJail}, the jail the
 * tools themselves use), size-capped.
 */
final class FilesMethods
{
    public const MAX_READ_BYTES = 1_048_576;

    public const MAX_DIFF_BYTES = 2_097_152;

    private const GIT_TIMEOUT_SECONDS = 10.0;

    private const REF_PATTERN = '/^[A-Za-z0-9._\/@{}~^-]{1,128}$/';

    /**
     * How far back `files.diff` looks for checkpoint bases — the same scan
     * width the TUI's own diff-review loop uses, so the web selector never
     * sees more bases than the terminal can walk.
     */
    private const CHECKPOINT_SCAN = 1000;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('files.diff', Scope::Read, 'The workspace\'s uncommitted changes to tracked files, as a unified diff.', self::diff(...)));
        $registry->add(MethodSpec::new('files.changed', Scope::Read, 'The workspace\'s changed and untracked files.', self::changed(...)));
        $registry->add(MethodSpec::new('files.read', Scope::Read, 'A file under the project root, read-only and size-capped.', self::read(...)));
    }

    /**
     * `files.diff`: the workspace's uncommitted changes as a unified diff,
     * either against HEAD (the plain `git diff` path) or against an earlier
     * checkpoint's captured workspace (`checkpoint: N`, counted the same way
     * `/diff n` counts on the terminal — 1 is the most recent base). Either
     * way the answer reports how many checkpoint bases exist for the session,
     * so a client can offer the choice without a second round-trip.
     *
     * @return array<string, mixed>
     */
    private static function diff(CallContext $call, Params $params): array
    {
        $checkpoint = $params->optionalInt('checkpoint', 1);
        if ($checkpoint !== null) {
            if ($params->has('ref')) {
                throw RpcError::invalidParams('ref and checkpoint are mutually exclusive', 'ref_with_checkpoint');
            }

            return self::diffAgainstCheckpoint($call, $params, $checkpoint);
        }

        $ref = $params->optionalString('ref', 128) ?? 'HEAD';
        if (\preg_match(self::REF_PATTERN, $ref) !== 1 || \str_starts_with($ref, '-')) {
            throw RpcError::invalidParams('ref is not a git revision');
        }
        $captured = self::git($call)->capture(self::MAX_DIFF_BYTES, 'diff', '--no-color', '--no-ext-diff', $ref, '--');
        self::refuseFailure($captured);

        return [
            'ref' => $ref,
            'diff' => $captured['stdout'],
            'truncated' => $captured['stdoutDropped'] > 0,
            'checkpoints' => self::checkpointBaseCount($call, $params),
            'omittedLines' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function diffAgainstCheckpoint(CallContext $call, Params $params, int $checkpoint): array
    {
        if (!$params->has('sessionId')) {
            throw RpcError::invalidParams('checkpoint needs a sessionId', 'checkpoint_without_session');
        }
        $store = $call->server->store();
        if ($store === null) {
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'this server has no session store', 'no_store');
        }
        $bases = self::checkpointBases($store, SessionMethods::sessionId($params));
        if ($bases === []) {
            throw RpcError::of(ErrorCode::Conflict, 'this session has no workspace checkpoints', 'no_checkpoints');
        }

        // Clamped like DiffReviewSource::load(): asking for more bases than
        // exist settles on the oldest one rather than refusing twice.
        $target = $bases[\min($checkpoint, \count($bases)) - 1];
        $diff = CheckpointDiff::of($store->workspaceCheckpointer(self::root($call)), $target['workspace']);
        if (\is_string($diff)) {
            throw RpcError::of(ErrorCode::Conflict, 'the checkpoint diff is unavailable: ' . $diff, 'checkpoint_diff_failed');
        }

        return [
            'ref' => 'checkpoint:' . $target['index'],
            'diff' => $diff->patch,
            'truncated' => $diff->omittedLines > 0,
            'checkpoints' => \count($bases),
            'omittedLines' => $diff->omittedLines,
        ];
    }

    /**
     * The session's checkpoint positions that actually carry a captured
     * workspace, newest first — the same `isCaptured` filter the TUI review
     * loop applies, so the RPC and the terminal agree on what a base is.
     *
     * @return list<array{index: int, workspace: array<array-key, mixed>}>
     */
    private static function checkpointBases(EnhancedSessionStore $store, string $sessionId): array
    {
        $bases = [];
        foreach (Checkpoints::fileCheckpointPositions($store, $sessionId, self::CHECKPOINT_SCAN) as $position) {
            $workspace = $position['workspace'];
            if (\is_array($workspace) && WorkspaceCheckpointer::isCaptured($workspace)) {
                $bases[] = ['index' => (int) $position['index'], 'workspace' => $workspace];
            }
        }

        return $bases;
    }

    /**
     * How many bases the HEAD-path answer should report: zero whenever a
     * count is impossible (no session named, no store) — the HEAD diff itself
     * stands regardless, so counting is best-effort, never a refusal.
     */
    private static function checkpointBaseCount(CallContext $call, Params $params): int
    {
        if (!$params->has('sessionId')) {
            return 0;
        }
        $store = $call->server->store();
        if ($store === null) {
            return 0;
        }

        return \count(self::checkpointBases($store, SessionMethods::sessionId($params)));
    }

    /** @return array<string, mixed> */
    private static function changed(CallContext $call, Params $params): array
    {
        $captured = self::git($call)->capture(self::MAX_DIFF_BYTES, 'status', '--porcelain=v1', '-z', '--untracked-files=all');
        self::refuseFailure($captured);

        $items = [];
        $records = \explode("\0", $captured['stdout']);
        for ($i = 0, $n = \count($records); $i < $n; $i++) {
            $record = $records[$i];
            if (\strlen($record) < 4) {
                continue;
            }
            $status = \substr($record, 0, 2);
            $item = ['path' => \substr($record, 3), 'status' => \trim($status) === '' ? $status : \trim($status)];
            if ($status[0] === 'R' || $status[0] === 'C') {
                // -z puts a rename's source in the record after it.
                $item['from'] = $records[++$i] ?? null;
            }
            $items[] = $item;
        }

        return ['items' => $items, 'truncated' => $captured['stdoutDropped'] > 0];
    }

    /** @return array<string, mixed> */
    private static function read(CallContext $call, Params $params): array
    {
        $root = self::root($call);
        $path = $params->string('path', 4096);
        $resolved = PathJail::resolve($root, $path);
        if ($resolved === null || !\is_file($resolved)) {
            throw RpcError::notFound('no readable file at that path under the project root', 'file_not_found');
        }
        $offset = $params->int('offset', 0, 0);
        $limit = $params->int('limit', self::MAX_READ_BYTES, 1, self::MAX_READ_BYTES);
        $size = (int) \filesize($resolved);
        $bytes = @\file_get_contents($resolved, false, null, \min($offset, $size), $limit);
        if ($bytes === false) {
            throw RpcError::of(ErrorCode::Conflict, 'the file could not be read', 'unreadable');
        }
        $binary = \str_contains($bytes, "\0");

        return [
            'path' => $path,
            'size' => $size,
            'offset' => $offset,
            'binary' => $binary,
            'content' => $binary ? '' : \mb_convert_encoding($bytes, 'UTF-8', 'UTF-8'),
            'truncated' => $offset + \strlen($bytes) < $size,
        ];
    }

    private static function git(CallContext $call): GitRunner
    {
        if (!GitRunner::available()) {
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'git is not available here', 'git_unavailable');
        }

        return GitRunner::new(self::root($call))->withInheritedGitEnv()->withTimeout(self::GIT_TIMEOUT_SECONDS);
    }

    /**
     * The project root one read call runs against: the serve --root when the
     * server has one, else the hub's opened workspace. Shared doctrine with
     * `fs.search` ({@see FsMethods::search()}) — every workspace-jailed read
     * answer stays inside exactly this directory.
     *
     * @throws RpcError when this server serves no project root at all
     */
    public static function root(CallContext $call): string
    {
        return $call->server->config()->root
            ?? $call->server->hub()->workspace()->root
            ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this server serves no project root', 'no_root');
    }

    /** @param array{stdout: string, stderr: string, exitCode: int, timedOut: bool} $captured */
    private static function refuseFailure(array $captured): void
    {
        if ($captured['timedOut']) {
            throw RpcError::of(ErrorCode::Busy, 'git did not answer in time', 'git_timeout');
        }
        if ($captured['exitCode'] !== 0) {
            throw RpcError::of(ErrorCode::Conflict, 'git could not answer: ' . \trim((string) \strtok($captured['stderr'], "\n")), 'git_failed');
        }
    }
}
