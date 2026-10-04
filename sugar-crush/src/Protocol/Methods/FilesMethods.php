<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Workspace\GitRunner;

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

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('files.diff', Scope::Read, 'The workspace\'s uncommitted changes to tracked files, as a unified diff.', self::diff(...)));
        $registry->add(MethodSpec::new('files.changed', Scope::Read, 'The workspace\'s changed and untracked files.', self::changed(...)));
        $registry->add(MethodSpec::new('files.read', Scope::Read, 'A file under the project root, read-only and size-capped.', self::read(...)));
    }

    /** @return array<string, mixed> */
    private static function diff(CallContext $call, Params $params): array
    {
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
        ];
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

    private static function root(CallContext $call): string
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
