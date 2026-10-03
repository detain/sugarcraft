<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workspace;

use SugarCraft\Crush\Support\HomeDirectory;

/**
 * Snapshots of the project's FILES, one per user turn, so a turn's edits can
 * be undone (item 3.A-1; the `/rewind --files`, `/undo` and `/diff` surface
 * is 3.A-2). `/rewind` restored only the transcript until now.
 *
 * IN A GIT WORK TREE (Cline 4.x, Zed): nothing the user can see changes —
 * no stash entry, no index write, no commit on their branch.
 *
 *  1. `git stash create` records the tracked changes (worktree and index) as
 *     a stash-shaped commit W without touching the stash list or the worktree.
 *  2. Untracked, non-ignored files go into a scratch `GIT_INDEX_FILE` and
 *     become a parentless commit U (`update-index --add --stdin`,
 *     `write-tree`, `commit-tree`), exactly as `git stash -u` lays them out.
 *  3. When there is anything to record, the two are joined into one
 *     `commit-tree <W^{tree}> -p HEAD -p <index> -p U` — the layout
 *     `git stash apply` understands. A clean tree records HEAD itself.
 *  4. The result is pinned at `refs/sugar-crush/checkpoints/<session>/<n>`, so
 *     `git gc` cannot prune it (Zed's checkpoints are unreferenced and can
 *     be), and so it never appears in `git stash list`.
 *
 * OUTSIDE ONE, a {@see ShadowRepo} holds the snapshot: a private git dir
 * beside the session database with the project as its work tree, one
 * parentless commit per turn, pinned the same way.
 *
 * WHAT IS LEFT OUT (Zed's limits): untracked files over
 * {@see MAX_UNTRACKED_FILE_BYTES}, past the first {@see MAX_UNTRACKED_FILES},
 * nested repositories, and anything matching {@see EXCLUDED_PATTERNS} —
 * build output, media, archives, binaries, databases, logs and `.env*`
 * files — on top of the project's own `.gitignore`. Tracked files are always
 * recorded. Because these are never snapshotted, a restore never deletes
 * them either.
 *
 * WHERE IT REFUSES: the home directory, a directory above it, and the
 * Desktop/Documents/Downloads folders directly inside it (Cline's list) —
 * a snapshot there is a copy of the user's whole life, and a restore there
 * is a disaster. A work tree rooted there (a dotfiles repository) is
 * refused the same way.
 *
 * A FAILURE NEVER FAILS THE TURN. Every outcome is an array the session
 * store keeps in the checkpoint row (`status` captured, refused or failed,
 * with a `reason`), so `/rewind --files` can say why there is nothing to
 * restore. A capture that runs out of its {@see CAPTURE_BUDGET_SECONDS}
 * disables checkpoints for that directory for the rest of the process
 * (Kilo's rule) rather than taxing every later turn with the same wait.
 *
 * THE STORED SHAPE (`captured`):
 * `kind` repo|shadow, `layout` stash|head|snapshot, `sha`, `base` (HEAD
 * when it was taken, null for an unborn branch and a shadow), `ref`,
 * `gitDir`, `workTree`, `untracked` (files recorded) and `skipped` (files
 * left out by the limits above).
 */
final class WorkspaceCheckpointer
{
    /** Private ref namespace; never pushed by a default refspec. */
    public const REF_PREFIX = 'refs/sugar-crush/checkpoints/';

    /** Zed's MAX_SIZE for an untracked file. */
    public const MAX_UNTRACKED_FILE_BYTES = 2 * 1024 * 1024;

    /** Untracked files recorded per snapshot; the rest count as skipped. */
    public const MAX_UNTRACKED_FILES = 5000;

    /** Wall-clock budget for one capture (Cline abandons at 15 s). */
    public const CAPTURE_BUDGET_SECONDS = 15.0;

    /** Wall-clock budget for one restore or diff. */
    public const RESTORE_BUDGET_SECONDS = 60.0;

    public const STATUS_CAPTURED = 'captured';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_FAILED = 'failed';

    /** Folders inside the home directory a checkpoint is refused in. */
    private const REFUSED_HOME_FOLDERS = ['Desktop', 'Documents', 'Downloads'];

    /**
     * Never snapshotted when untracked (Zed's checkpoint.gitignore, Cline's
     * CheckpointExclusions): regenerable trees, and bulky or binary files
     * whose copy would cost more than it could give back.
     */
    public const EXCLUDED_PATTERNS = [
        '.git/',
        'node_modules/',
        'vendor/',
        'bower_components/',
        '.venv/',
        'venv/',
        '__pycache__/',
        '.tox/',
        'dist/',
        'build/',
        'target/',
        'out/',
        '.next/',
        '.nuxt/',
        '.cache/',
        '.gradle/',
        '.idea/',
        'coverage/',
        '.phpunit.cache/',
        '*.png', '*.jpg', '*.jpeg', '*.gif', '*.bmp', '*.ico', '*.webp', '*.tif', '*.tiff', '*.psd', '*.heic',
        '*.mp3', '*.mp4', '*.m4a', '*.mov', '*.avi', '*.mkv', '*.wav', '*.flac', '*.ogg', '*.webm',
        '*.pdf', '*.ttf', '*.otf', '*.woff', '*.woff2',
        '*.zip', '*.tar', '*.gz', '*.tgz', '*.bz2', '*.xz', '*.zst', '*.7z', '*.rar', '*.jar', '*.war', '*.iso', '*.dmg',
        '*.exe', '*.dll', '*.so', '*.dylib', '*.o', '*.a', '*.obj', '*.class', '*.pyc', '*.wasm', '*.bin',
        '*.sqlite', '*.sqlite3', '*.db', '*.db-wal', '*.db-shm', '*.db-journal', '*.parquet',
        '*.log',
        '.env*',
    ];

    /** @var array<string, string> work tree => why checkpoints are off there */
    private static array $disabled = [];

    private function __construct(
        private readonly string $root,
        private readonly ?string $shadowBase = null,
        private readonly float $captureBudget = self::CAPTURE_BUDGET_SECONDS,
    ) {}

    /** A checkpointer for the project at $root. */
    public static function new(string $root): self
    {
        return new self($root);
    }

    /**
     * Where shadow repositories for non-git projects go; null (the default)
     * refuses non-git projects.
     */
    public function withShadowBase(?string $directory): self
    {
        return new self($this->root, $directory, $this->captureBudget);
    }

    /** Bound a capture at $seconds instead of {@see CAPTURE_BUDGET_SECONDS}. */
    public function withCaptureBudget(float $seconds): self
    {
        return new self($this->root, $this->shadowBase, max(0.001, $seconds));
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The pinning ref for checkpoint $index of $sessionId. A session id that
     * is not a safe ref component is hashed, so any id maps to a valid,
     * distinct name.
     */
    public static function refFor(string $sessionId, int $index): string
    {
        $component = preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $sessionId) === 1
            ? $sessionId
            : 'x' . sha1($sessionId);

        return self::REF_PREFIX . $component . '/' . max(0, $index);
    }

    /**
     * Why checkpoints are refused at $root, or null when they are allowed.
     */
    public static function refusalFor(string $root): ?string
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            return 'the project directory ' . $root . ' does not exist';
        }

        $home = HomeDirectory::resolved();
        $homeReal = $home === null ? false : realpath($home);
        if ($homeReal === false) {
            return null;
        }

        if ($real === $homeReal) {
            return 'checkpoints are not taken in the home directory';
        }
        // Both sides are resolved, so "contains the home directory" is
        // "is one of its ancestors" — walked, not prefix-matched.
        for ($up = \dirname($homeReal); ; $up = \dirname($up)) {
            if ($up === $real) {
                return 'checkpoints are not taken in a directory that contains the home directory';
            }
            if ($up === \dirname($up)) {
                break;
            }
        }
        foreach (self::REFUSED_HOME_FOLDERS as $folder) {
            if ($real === $homeReal . '/' . $folder) {
                return 'checkpoints are not taken in ~/' . $folder;
            }
        }

        return null;
    }

    /** Re-enable every directory a timed-out capture switched off. For tests. */
    public static function forgetDisabled(): void
    {
        self::$disabled = [];
    }

    /**
     * Snapshot the workspace for checkpoint $index of $sessionId and pin it.
     *
     * @return array<string, mixed> the stored shape (see the class doc-block)
     */
    public function capture(string $sessionId, int $index): array
    {
        $refusal = self::refusalFor($this->root);
        if ($refusal !== null) {
            return self::refused($refusal);
        }
        $root = (string) realpath($this->root);
        if (isset(self::$disabled[$root])) {
            return self::refused('checkpoints are off for this directory: ' . self::$disabled[$root]);
        }
        if (!GitRunner::available()) {
            return self::refused('git is not installed');
        }

        $deadline = microtime(true) + $this->captureBudget;
        $git = GitRunner::new($root)->withDeadline($deadline);
        $ref = self::refFor($sessionId, $index);
        $label = 'sugar-crush checkpoint ' . $sessionId . '/' . $index;

        $probe = $git->run('rev-parse', '--show-toplevel', '--absolute-git-dir');
        if ($probe['timedOut']) {
            return $this->giveUp($root, 'git rev-parse timed out');
        }

        $outcome = $probe['ok']
            ? $this->captureRepo($git, $probe['stdout'], $ref, $label)
            : $this->captureShadow($git, $root, $ref, $label);

        if (($outcome['status'] ?? null) === self::STATUS_FAILED && ($outcome['timedOut'] ?? false) === true) {
            unset($outcome['timedOut']);
            self::$disabled[$root] = (string) $outcome['reason'];
        }
        unset($outcome['timedOut']);

        return $outcome;
    }

    /**
     * Put the files back the way checkpoint $workspace recorded them.
     *
     * Refused when HEAD has moved since the checkpoint (a restore would
     * silently undo those commits — Cline's rule). Files the checkpoint never
     * recorded (ignored, excluded, too large) are left alone, so nothing a
     * limit skipped is ever deleted. Files created since the checkpoint that
     * it WOULD have recorded are deleted; changed and deleted ones are
     * rewritten; the index goes back to the checkpoint's index.
     *
     * @param array<string, mixed> $workspace a captured shape from {@see capture()}
     * @return array{status: string, written: int, deleted: int, reason: string}
     */
    public function restore(array $workspace): array
    {
        $prepared = $this->prepare($workspace);
        if (\is_string($prepared)) {
            return ['status' => self::STATUS_REFUSED, 'written' => 0, 'deleted' => 0, 'reason' => $prepared];
        }
        [$git, $target, $current] = $prepared;

        if ($target === $current) {
            return ['status' => 'unchanged', 'written' => 0, 'deleted' => 0, 'reason' => 'the files already match the checkpoint'];
        }

        $changes = self::diffTrees($git, $target, $current);
        if ($changes === null) {
            return self::restoreFailure('git diff-tree failed');
        }

        $workTree = (string) $workspace['workTree'];
        $write = [];
        $deleted = 0;
        foreach ($changes as [$status, $path]) {
            if (!self::safeRelativePath($path)) {
                return self::restoreFailure('refusing an unsafe path from git: ' . $path);
            }
            if ($status === 'A') {
                $deleted += self::removeFile($workTree, $path) ? 1 : 0;
            } else {
                $write[] = $path;
            }
        }

        if ($write !== []) {
            $scratch = self::scratchIndex();
            if ($scratch === null) {
                return self::restoreFailure('could not create a scratch index');
            }
            try {
                $staged = $git->withIndexFile($scratch);
                $read = $staged->run('read-tree', $target);
                if (!$read['ok']) {
                    return self::restoreFailure('git read-tree failed: ' . self::why($read));
                }
                $checkout = $staged->runWithInput(implode("\0", $write) . "\0", 'checkout-index', '-f', '-z', '--stdin');
                if (!$checkout['ok']) {
                    return self::restoreFailure('git checkout-index failed: ' . self::why($checkout));
                }
            } finally {
                @unlink($scratch);
            }
        }

        // The index goes back to the checkpoint's: for a repository that is
        // what was staged when it was taken; for a shadow, the snapshot.
        $index = match ((string) $workspace['layout']) {
            'stash' => self::revParse($git, (string) $workspace['sha'] . '^2'),
            'head' => (string) $workspace['base'],
            default => $workspace['kind'] === 'shadow' ? $target : null,
        };
        if ($index !== null && $index !== '') {
            $reset = $git->run('read-tree', $index);
            if (!$reset['ok']) {
                return self::restoreFailure('git read-tree of the checkpoint index failed: ' . self::why($reset));
            }
            $git->run('update-index', '-q', '--refresh');
        }

        return ['status' => 'restored', 'written' => \count($write), 'deleted' => $deleted, 'reason' => ''];
    }

    /**
     * What a restore of $workspace would change: `[status, path]` pairs as
     * `git diff --name-status <checkpoint> <now>` spells them (A = created
     * since, so a restore deletes it; D/M/T = a restore rewrites it), empty
     * when it would change nothing, or the reason no answer is possible.
     *
     * @param array<string, mixed> $workspace
     * @return list<array{0: string, 1: string}>|string
     */
    public function changes(array $workspace): array|string
    {
        $prepared = $this->prepare($workspace);
        if (\is_string($prepared)) {
            return $prepared;
        }
        [$git, $target, $current] = $prepared;
        if ($target === $current) {
            return [];
        }

        return self::diffTrees($git, $target, $current) ?? 'git diff-tree failed';
    }

    /**
     * Delete the pinning refs of every captured shape in $workspaces, one
     * `update-ref --stdin` per repository. Best effort: a repository that is
     * gone has no refs left to leak.
     *
     * @param list<array<string, mixed>> $workspaces
     */
    public static function dropRefs(array $workspaces): void
    {
        $byRepo = [];
        foreach ($workspaces as $workspace) {
            if (!self::isCaptured($workspace)) {
                continue;
            }
            $byRepo[(string) $workspace['gitDir']][(string) $workspace['ref']] = true;
        }

        foreach ($byRepo as $gitDir => $refs) {
            if (!is_dir($gitDir) || !GitRunner::available()) {
                continue;
            }
            $script = '';
            foreach (array_keys($refs) as $ref) {
                $script .= 'delete ' . $ref . "\n";
            }
            GitRunner::new($gitDir)->withGitDir($gitDir)->runWithInput($script, 'update-ref', '--stdin');
        }
    }

    /**
     * Pin each captured shape's commit under a second ref, for a session
     * copied by `/branch`.
     *
     * @param list<array{0: array<string, mixed>, 1: string}> $pairs [captured shape, new ref]
     */
    public static function copyRefs(array $pairs): void
    {
        $byRepo = [];
        foreach ($pairs as [$workspace, $ref]) {
            if (!self::isCaptured($workspace)) {
                continue;
            }
            $byRepo[(string) $workspace['gitDir']][$ref] = (string) $workspace['sha'];
        }

        foreach ($byRepo as $gitDir => $refs) {
            if (!is_dir($gitDir) || !GitRunner::available()) {
                continue;
            }
            $script = '';
            foreach ($refs as $ref => $sha) {
                $script .= 'update ' . $ref . ' ' . $sha . "\n";
            }
            GitRunner::new($gitDir)->withGitDir($gitDir)->runWithInput($script, 'update-ref', '--stdin');
        }
    }

    /**
     * Whether $workspace is a pinned snapshot (as opposed to a refusal, a
     * failure, or something that is not a stored shape at all).
     */
    public static function isCaptured(mixed $workspace): bool
    {
        return \is_array($workspace)
            && ($workspace['status'] ?? null) === self::STATUS_CAPTURED
            && \is_string($workspace['gitDir'] ?? null)
            && \is_string($workspace['ref'] ?? null) && str_starts_with($workspace['ref'], self::REF_PREFIX)
            && \is_string($workspace['sha'] ?? null) && preg_match('/^[0-9a-f]{40,64}$/', $workspace['sha']) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function captureRepo(GitRunner $git, string $probe, string $ref, string $label): array
    {
        $lines = explode("\n", $probe);
        $workTree = $lines[0] ?? '';
        $gitDir = $lines[1] ?? '';
        if ($workTree === '' || $gitDir === '' || !is_dir($workTree)) {
            return self::failed('git did not report a work tree', false);
        }
        $refusal = self::refusalFor($workTree);
        if ($refusal !== null) {
            return self::refused($refusal);
        }

        $git = GitRunner::new($workTree)->withGitDir($gitDir, $workTree)->withDeadline($this->deadlineOf($git));
        $base = self::revParse($git, 'HEAD^{commit}');

        $untracked = $this->untrackedPaths($git);
        if (\is_array($untracked) === false) {
            return $untracked === 'timeout' ? self::failed('listing untracked files timed out', true) : self::failed($untracked, false);
        }
        [$paths, $skipped] = $untracked;

        if ($base === null) {
            // An unborn branch has nothing for `stash create` to stash
            // against: record the whole tree as one parentless commit.
            $tree = $this->treeOf($git, $gitDir . '/index', $paths, true);
            if (\is_array($tree)) {
                return $tree;
            }
            $sha = self::commitTree($git, $tree, [], $label);
            if (\is_array($sha)) {
                return $sha;
            }

            return $this->pin($git, 'repo', 'snapshot', $sha, null, $ref, $gitDir, $workTree, \count($paths), $skipped);
        }

        $stash = $git->run('stash', 'create', $label);
        if (!$stash['ok']) {
            return self::failed('git stash create failed: ' . self::why($stash), $stash['timedOut']);
        }
        $stashSha = trim($stash['stdout']);

        $untrackedCommit = null;
        if ($paths !== []) {
            $tree = $this->treeOf($git, null, $paths, false);
            if (\is_array($tree)) {
                return $tree;
            }
            $untrackedCommit = self::commitTree($git, $tree, [], 'untracked files on ' . $label);
            if (\is_array($untrackedCommit)) {
                return $untrackedCommit;
            }
        }

        if ($stashSha === '' && $untrackedCommit === null) {
            return $this->pin($git, 'repo', 'head', $base, $base, $ref, $gitDir, $workTree, 0, $skipped);
        }

        if ($untrackedCommit === null) {
            return $this->pin($git, 'repo', 'stash', $stashSha, $base, $ref, $gitDir, $workTree, 0, $skipped);
        }

        if ($stashSha === '') {
            // Tracked files are clean, so the worktree tree and the index
            // tree are both HEAD's.
            $worktreeTree = $base . '^{tree}';
            $indexCommit = self::commitTree($git, $worktreeTree, [$base], 'index on ' . $label);
        } else {
            $worktreeTree = $stashSha . '^{tree}';
            $indexCommit = self::revParse($git, $stashSha . '^2') ?? self::failed('the stash commit has no index parent', false);
        }
        if (\is_array($indexCommit)) {
            return $indexCommit;
        }
        $sha = self::commitTree($git, $worktreeTree, [$base, $indexCommit, $untrackedCommit], $label);
        if (\is_array($sha)) {
            return $sha;
        }

        return $this->pin($git, 'repo', 'stash', $sha, $base, $ref, $gitDir, $workTree, \count($paths), $skipped);
    }

    /**
     * @return array<string, mixed>
     */
    private function captureShadow(GitRunner $git, string $root, string $ref, string $label): array
    {
        if ($this->shadowBase === null || $this->shadowBase === '') {
            return self::refused('not a git work tree, and this session store keeps no shadow repositories');
        }
        $shadow = ShadowRepo::forRoot($root, $this->shadowBase);
        if ($shadow === null) {
            return self::refused('the project directory ' . $root . ' does not exist');
        }
        $refusal = $shadow->refusal();
        if ($refusal !== null) {
            return self::refused($refusal);
        }
        $error = $shadow->ensure($git, self::EXCLUDED_PATTERNS);
        if ($error !== null) {
            return self::failed($error, str_contains($error, 'timed out'));
        }

        $git = GitRunner::new($shadow->workTree())
            ->withGitDir($shadow->gitDir(), $shadow->workTree())
            ->withDeadline($this->deadlineOf($git));

        $update = $git->run('add', '-u');
        if (!$update['ok']) {
            return self::failed('git add -u failed in the shadow repository: ' . self::why($update), $update['timedOut']);
        }
        $untracked = $this->untrackedPaths($git);
        if (\is_array($untracked) === false) {
            return $untracked === 'timeout' ? self::failed('listing untracked files timed out', true) : self::failed($untracked, false);
        }
        [$paths, $skipped] = $untracked;
        if ($paths !== []) {
            $add = $git->runWithInput(implode("\0", $paths) . "\0", 'update-index', '--add', '-z', '--stdin');
            if (!$add['ok']) {
                return self::failed('git update-index failed in the shadow repository: ' . self::why($add), $add['timedOut']);
            }
        }
        $tree = $git->run('write-tree');
        if (!$tree['ok']) {
            return self::failed('git write-tree failed in the shadow repository: ' . self::why($tree), $tree['timedOut']);
        }
        // Parentless on purpose: chaining each snapshot to the last would
        // keep every old one reachable after its ref is dropped.
        $sha = self::commitTree($git, trim($tree['stdout']), [], $label);
        if (\is_array($sha)) {
            return $sha;
        }

        return $this->pin($git, 'shadow', 'snapshot', $sha, null, $ref, $shadow->gitDir(), $shadow->workTree(), \count($paths), $skipped);
    }

    /**
     * Untracked, non-ignored, non-excluded files within the limits, and the
     * number left out; 'timeout' or a reason on failure.
     *
     * @return array{0: list<string>, 1: int}|string
     */
    private function untrackedPaths(GitRunner $git): array|string
    {
        $excludes = self::excludesFile();
        if ($excludes === null) {
            return 'could not write the checkpoint exclude list';
        }
        try {
            $list = $git->run('ls-files', '--others', '--exclude-standard', '-z', '--exclude-from=' . $excludes);
        } finally {
            @unlink($excludes);
        }
        if (!$list['ok']) {
            return $list['timedOut'] ? 'timeout' : 'git ls-files failed: ' . self::why($list);
        }

        $workTree = (string) $git->workTree();
        $paths = [];
        $skipped = 0;
        foreach (explode("\0", $list['stdout']) as $path) {
            if ($path === '') {
                continue;
            }
            // A nested repository is listed as its directory; a snapshot of
            // it would be a gitlink with nothing behind it.
            if (str_ends_with($path, '/') || !self::safeRelativePath($path)) {
                $skipped++;
                continue;
            }
            $full = $workTree . '/' . $path;
            if (!is_link($full)) {
                $size = is_file($full) ? @filesize($full) : false;
                if ($size === false || $size > self::MAX_UNTRACKED_FILE_BYTES) {
                    $skipped++;
                    continue;
                }
            }
            if (\count($paths) >= self::MAX_UNTRACKED_FILES) {
                $skipped++;
                continue;
            }
            $paths[] = $path;
        }

        return [$paths, $skipped];
    }

    /**
     * The tree of $paths staged into a scratch index — seeded from
     * $seedIndex (then refreshed with `add -u`) or empty.
     *
     * @param list<string> $paths
     * @return string|array<string, mixed> the tree, or a failed shape
     */
    private function treeOf(GitRunner $git, ?string $seedIndex, array $paths, bool $updateTracked): string|array
    {
        $scratch = self::scratchIndex();
        if ($scratch === null) {
            return self::failed('could not create a scratch index', false);
        }
        try {
            if ($seedIndex !== null && is_file($seedIndex) && !@copy($seedIndex, $scratch)) {
                return self::failed('could not copy the index', false);
            }
            $staged = $git->withIndexFile($scratch);
            if ($updateTracked) {
                $update = $staged->run('add', '-u');
                if (!$update['ok']) {
                    return self::failed('git add -u failed: ' . self::why($update), $update['timedOut']);
                }
            }
            if ($paths !== []) {
                $add = $staged->runWithInput(implode("\0", $paths) . "\0", 'update-index', '--add', '-z', '--stdin');
                if (!$add['ok']) {
                    return self::failed('git update-index failed: ' . self::why($add), $add['timedOut']);
                }
            }
            $tree = $staged->run('write-tree');
            if (!$tree['ok']) {
                return self::failed('git write-tree failed: ' . self::why($tree), $tree['timedOut']);
            }

            return trim($tree['stdout']);
        } finally {
            @unlink($scratch);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pin(
        GitRunner $git,
        string $kind,
        string $layout,
        string $sha,
        ?string $base,
        string $ref,
        string $gitDir,
        string $workTree,
        int $untracked,
        int $skipped,
    ): array {
        $update = $git->run('update-ref', '-m', 'sugar-crush checkpoint', $ref, $sha);
        if (!$update['ok']) {
            return self::failed('git update-ref failed: ' . self::why($update), $update['timedOut']);
        }

        return [
            'status' => self::STATUS_CAPTURED,
            'kind' => $kind,
            'layout' => $layout,
            'sha' => $sha,
            'base' => $base,
            'ref' => $ref,
            'gitDir' => $gitDir,
            'workTree' => $workTree,
            'untracked' => $untracked,
            'skipped' => $skipped,
        ];
    }

    /**
     * The runner, target tree and current tree a restore or diff works from,
     * or the reason it cannot.
     *
     * @param array<string, mixed> $workspace
     * @return array{0: GitRunner, 1: string, 2: string}|string
     */
    private function prepare(array $workspace): array|string
    {
        if (!self::isCaptured($workspace)) {
            $reason = \is_string($workspace['reason'] ?? null) ? $workspace['reason'] : 'no workspace snapshot was taken';

            return 'this checkpoint has no workspace snapshot: ' . $reason;
        }
        $workTree = (string) ($workspace['workTree'] ?? '');
        $gitDir = (string) $workspace['gitDir'];
        $refusal = self::refusalFor($workTree);
        if ($refusal !== null) {
            return $refusal;
        }
        if (!is_dir($gitDir)) {
            return 'the repository that held this snapshot is gone: ' . $gitDir;
        }
        if (!GitRunner::available()) {
            return 'git is not installed';
        }

        $git = GitRunner::new($workTree)
            ->withGitDir($gitDir, $workTree)
            ->withDeadline(microtime(true) + self::RESTORE_BUDGET_SECONDS);
        $sha = (string) $workspace['sha'];
        if (self::revParse($git, $sha . '^{commit}') === null) {
            return 'the snapshot commit ' . $sha . ' is no longer in the repository';
        }

        if ($workspace['kind'] === 'repo') {
            $head = self::revParse($git, 'HEAD^{commit}');
            $base = \is_string($workspace['base'] ?? null) ? $workspace['base'] : null;
            if ($head !== $base) {
                return 'HEAD has moved since this checkpoint (' . ($base ?? 'unborn') . ' → ' . ($head ?? 'unborn')
                    . '); restoring would undo those commits. Move the branch back first, or restore the chat only';
            }
        }

        $target = $this->targetTree($git, $workspace);
        if ($target === null) {
            return 'could not read the snapshot tree';
        }
        $current = $this->currentTree($git, $workspace);
        if (\is_array($current)) {
            return (string) $current['reason'];
        }

        return [$git, $target, $current];
    }

    /**
     * The full tree checkpoint $workspace recorded: tracked state plus the
     * untracked files, as one tree.
     *
     * @param array<string, mixed> $workspace
     */
    private function targetTree(GitRunner $git, array $workspace): ?string
    {
        $sha = (string) $workspace['sha'];
        $tree = self::revParse($git, $sha . '^{tree}');
        if ($tree === null || $workspace['layout'] !== 'stash') {
            return $tree;
        }

        $untracked = self::revParse($git, $sha . '^3');
        if ($untracked === null) {
            return $tree;
        }

        $listing = $git->run('ls-tree', '-r', '-z', '--full-tree', $untracked);
        if (!$listing['ok']) {
            return null;
        }
        $scratch = self::scratchIndex();
        if ($scratch === null) {
            return null;
        }
        try {
            $staged = $git->withIndexFile($scratch);
            if (!$staged->run('read-tree', $tree)['ok']) {
                return null;
            }
            $info = $listing['stdout'];
            if ($info !== '' && !str_ends_with($info, "\0")) {
                $info .= "\0";
            }
            if ($info !== '' && !$staged->runWithInput($info, 'update-index', '-z', '--index-info')['ok']) {
                return null;
            }
            $written = $staged->run('write-tree');

            return $written['ok'] ? trim($written['stdout']) : null;
        } finally {
            @unlink($scratch);
        }
    }

    /**
     * The tree the workspace would snapshot to now, under the same limits,
     * without pinning anything or touching the real index.
     *
     * @param array<string, mixed> $workspace
     * @return string|array<string, mixed>
     */
    private function currentTree(GitRunner $git, array $workspace): string|array
    {
        $untracked = $this->untrackedPaths($git);
        if (\is_array($untracked) === false) {
            return self::failed($untracked === 'timeout' ? 'listing untracked files timed out' : $untracked, $untracked === 'timeout');
        }

        return $this->treeOf($git, (string) $workspace['gitDir'] . '/index', $untracked[0], true);
    }

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    private static function diffTrees(GitRunner $git, string $from, string $to): ?array
    {
        $diff = $git->run('diff-tree', '-r', '-z', '--no-renames', '--name-status', $from, $to);
        if (!$diff['ok']) {
            return null;
        }
        $fields = explode("\0", $diff['stdout']);
        $changes = [];
        for ($i = 0; $i + 1 < \count($fields); $i += 2) {
            if ($fields[$i] === '') {
                break;
            }
            $changes[] = [$fields[$i][0], $fields[$i + 1]];
        }

        return $changes;
    }

    /**
     * @param list<string> $parents
     * @return string|array<string, mixed>
     */
    private static function commitTree(GitRunner $git, string $tree, array $parents, string $message): string|array
    {
        $args = ['commit-tree', $tree];
        foreach ($parents as $parent) {
            $args[] = '-p';
            $args[] = $parent;
        }
        $args[] = '-m';
        $args[] = $message;
        $commit = $git->run(...$args);
        if (!$commit['ok']) {
            return self::failed('git commit-tree failed: ' . self::why($commit), $commit['timedOut']);
        }

        return trim($commit['stdout']);
    }

    private static function revParse(GitRunner $git, string $spec): ?string
    {
        $parsed = $git->run('rev-parse', '-q', '--verify', $spec);
        $sha = trim($parsed['stdout']);

        return $parsed['ok'] && preg_match('/^[0-9a-f]{40,64}$/', $sha) === 1 ? $sha : null;
    }

    private static function removeFile(string $workTree, string $path): bool
    {
        $full = $workTree . '/' . $path;
        if (!is_link($full) && !is_file($full)) {
            return false;
        }
        if (!@unlink($full)) {
            return false;
        }
        // Prune the directories the deletion emptied, never the work tree:
        // one rmdir per directory segment of the (already validated) path.
        $dir = \dirname($full);
        for ($depth = substr_count($path, '/'); $depth > 0; $depth--, $dir = \dirname($dir)) {
            if (!@rmdir($dir)) {
                break;
            }
        }

        return true;
    }

    private static function safeRelativePath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if ($segment === '..' || $segment === '.git') {
                return false;
            }
        }

        return true;
    }

    private static function scratchIndex(): ?string
    {
        $file = @tempnam(sys_get_temp_dir(), 'sc-idx-');
        if ($file === false) {
            return null;
        }
        // git wants to create the index itself; an empty file is a corrupt one.
        @unlink($file);

        return $file;
    }

    private static function excludesFile(): ?string
    {
        $file = @tempnam(sys_get_temp_dir(), 'sc-exc-');
        if ($file === false) {
            return null;
        }
        if (@file_put_contents($file, implode("\n", self::EXCLUDED_PATTERNS) . "\n") === false) {
            @unlink($file);

            return null;
        }

        return $file;
    }

    private function deadlineOf(GitRunner $git): float
    {
        // The probe runner carries the capture's deadline; the repository
        // runners built after it keep that one rather than restarting it.
        return $git->deadline() ?? microtime(true) + $this->captureBudget;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function why(array $result): string
    {
        if (($result['timedOut'] ?? false) === true) {
            return 'timed out';
        }
        $stderr = trim((string) ($result['stderr'] ?? ''));

        return $stderr !== '' ? $stderr : 'exit ' . (int) ($result['exitCode'] ?? 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function giveUp(string $root, string $reason): array
    {
        self::$disabled[$root] = $reason;

        return ['status' => self::STATUS_FAILED, 'reason' => $reason];
    }

    /**
     * @return array<string, mixed>
     */
    private static function refused(string $reason): array
    {
        return ['status' => self::STATUS_REFUSED, 'reason' => $reason];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failed(string $reason, bool $timedOut): array
    {
        return ['status' => self::STATUS_FAILED, 'reason' => $reason, 'timedOut' => $timedOut];
    }

    /**
     * @return array{status: string, written: int, deleted: int, reason: string}
     */
    private static function restoreFailure(string $reason): array
    {
        return ['status' => self::STATUS_FAILED, 'written' => 0, 'deleted' => 0, 'reason' => $reason];
    }
}
