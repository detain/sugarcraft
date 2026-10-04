<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workspace;

/**
 * Opt-in auto-commit (step 3.G, Aider's git loop): the `autoCommit` setting
 * — `off` (the default), `turn` or `edit` — commits what sugar-crush changed,
 * and `/undo` reverts such a commit under Aider's refusals.
 *
 *  - `turn`: when a turn settles, every file changed since the checkpoint
 *    taken before its prompt is committed in one commit, with a
 *    Conventional-Commits subject from the cheap title model
 *    ({@see CommitMessageWriter}). Driven by {@see \SugarCraft\Crush\Chat}.
 *  - `edit`: each `Write`/`Edit` is committed as it lands, with the call's
 *    own `description` as the subject
 *    ({@see \SugarCraft\Crush\Hooks\BuiltIn\AutoCommitHook}).
 *
 * WHAT IS NEVER DONE, and why each is a rule here rather than a default:
 *
 *  - NEVER `--no-verify`. Aider skips the user's pre-commit hooks by default;
 *    that is the one part of its loop not copied. Every commit here runs the
 *    repository's own hooks — {@see GitRunner} pins `core.hooksPath` to
 *    `/dev/null` for its private plumbing, so a commit names the hooks
 *    directory the user's own configuration resolves to ({@see hooksPath()}).
 *    A hook that rejects the commit fails it, and the edit stays uncommitted.
 *  - NEVER MIXES THE USER'S WORK WITH THE MODEL'S. A file the user had
 *    already changed before the turn is first committed as it was then, in its
 *    own commit ({@see SNAPSHOT_SUBJECT}), and the model's change goes on top
 *    — Aider's "dirty commit", which is what lets `/undo` take back exactly the
 *    model's part. What the user had is read from the workspace checkpoint
 *    taken before the prompt (step 3.A-1); with no checkpoint to read it from,
 *    a file with changes is NOT committed at all rather than committed mixed.
 *  - NEVER COMMITS WHAT THE USER STAGED ELSEWHERE. Commits are pathspec-limited
 *    to the files sugar-crush changed; the rest of the index is left as it was.
 *
 * Authorship: the commit's author is the user (`user.name`/`user.email`), and
 * the trailer is the `attribution.commit` setting (step 0.3) or, unset,
 * {@see DEFAULT_TRAILER}. The COMMITTER is {@see GitRunner}'s fixed identity:
 * that runner pins it for every call it makes, and an argument cannot
 * override an environment variable.
 *
 * Which commits are "ours" is recorded per session in the repository's git
 * directory ({@see RECORD_PATH}), so `/undo` reverts only a commit this
 * session made — in whichever process made it.
 */
final class AutoCommitter
{
    public const SETTINGS_KEY = 'autoCommit';

    public const MODE_OFF = 'off';
    public const MODE_TURN = 'turn';
    public const MODE_EDIT = 'edit';

    /** @var list<string> */
    public const MODES = [self::MODE_OFF, self::MODE_TURN, self::MODE_EDIT];

    /** The subject of the commit that saves the user's own changes first. */
    public const SNAPSHOT_SUBJECT = 'chore: snapshot user changes before sugar-crush edit';

    /** The trailer when `attribution.commit` is unset. */
    public const DEFAULT_TRAILER = 'Co-authored-by: sugar-crush <sugar-crush@noreply.invalid>';

    /** A commit's bound: the user's pre-commit hooks run inside it. */
    public const COMMIT_TIMEOUT_SECONDS = 120.0;

    /** Every other git call's bound. */
    private const READ_TIMEOUT_SECONDS = 10.0;

    /** Where the session's own commits are recorded, inside the git directory. */
    public const RECORD_PATH = 'sugar-crush/auto-commits.jsonl';

    /** Records kept; older ones are dropped. */
    private const MAX_RECORDS = 500;

    private const NULL_SHA = '0000000000000000000000000000000000000000';

    /** `/undo`'s refusals, by name — Aider's five, plus the root commit. */
    public const REFUSED_NOT_OURS = 'not-ours';
    public const REFUSED_ROOT = 'root-commit';
    public const REFUSED_MERGE = 'merge-commit';
    public const REFUSED_DIRTY = 'dirty-files';
    public const REFUSED_ABSENT_IN_PARENT = 'absent-in-parent';
    public const REFUSED_PUSHED = 'already-pushed';

    private function __construct(
        private readonly string $root,
        private readonly string $trailer = self::DEFAULT_TRAILER,
        private readonly string $sessionId = '',
    ) {}

    /** An auto-committer for the project at $root. */
    public static function new(string $root): self
    {
        return new self($root);
    }

    /** The `autoCommit` setting as a mode; anything unrecognised is `off`. */
    public static function modeFrom(mixed $raw): string
    {
        return \is_string($raw) && \in_array($raw, self::MODES, true) ? $raw : self::MODE_OFF;
    }

    /**
     * The trailer for the `attribution` setting: its `commit` string when it
     * names one, none when it is set to the empty string, else
     * {@see DEFAULT_TRAILER}.
     */
    public static function trailerFor(mixed $attribution): string
    {
        if (\is_array($attribution) && \array_key_exists('commit', $attribution) && \is_string($attribution['commit'])) {
            return trim($attribution['commit']);
        }

        return self::DEFAULT_TRAILER;
    }

    public function withTrailer(string $trailer): self
    {
        return $this->mutate(['trailer' => $trailer]);
    }

    public function withSessionId(string $sessionId): self
    {
        return $this->mutate(['sessionId' => $sessionId]);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The repository's top level, or null when $root is not inside a git work
     * tree (or git is not installed).
     */
    public function topLevel(): ?string
    {
        if (!GitRunner::available()) {
            return null;
        }
        $top = $this->git($this->root)->run('rev-parse', '--show-toplevel');

        return $top['ok'] && trim($top['stdout']) !== '' ? trim($top['stdout']) : null;
    }

    // -------------------------------------------------------------------------
    // Turn mode
    // -------------------------------------------------------------------------

    /**
     * Ready a `turn`-mode commit: the files changed since the checkpoint
     * $workspace recorded (still differing from HEAD), with the user's own
     * earlier changes to them already committed first. Null when nothing is
     * left to commit; a string says why nothing can be.
     *
     * @param array<string, mixed> $workspace
     * @return array{paths: list<string>, diff: string, snapshot: ?string}|string|null
     */
    public function prepareTurn(WorkspaceCheckpointer $checkpointer, array $workspace): array|string|null
    {
        if (($workspace['kind'] ?? null) !== 'repo' || !WorkspaceCheckpointer::isCaptured($workspace)) {
            return 'no checkpoint of the files was taken before this turn';
        }
        $top = $this->topLevel();
        if ($top === null) {
            return 'the project is not a git work tree';
        }

        $trees = $checkpointer->trees($workspace);
        if (\is_string($trees)) {
            return $trees;
        }
        [$git, $target, $current] = $trees;
        if ($target === $current) {
            return null;
        }
        $changes = WorkspaceCheckpointer::diffTrees($git, $target, $current);
        if ($changes === null) {
            return 'git diff-tree failed';
        }

        $paths = $this->dirtyAmong($top, array_column($changes, 1));
        if ($paths === []) {
            return null;
        }

        $snapshot = $this->snapshotUserChanges($top, $paths, $workspace);
        if (\is_string($snapshot)) {
            return $snapshot;
        }

        return ['paths' => $paths, 'diff' => $this->diff($top, $paths), 'snapshot' => $snapshot['sha']];
    }

    // -------------------------------------------------------------------------
    // Edit mode
    // -------------------------------------------------------------------------

    /**
     * Commit the one file an `Edit`/`Write` just changed. Its subject is
     * $subject applied to the file's path relative to the work tree (see
     * {@see CommitMessageWriter::fromDescription()}). Null when there is
     * nothing to commit — not in this work tree (a nested repository's file
     * included), ignored, or unchanged; otherwise the commit's outcome.
     *
     * Which work tree a file belongs to is git's answer, asked from the file's
     * own directory, not a string compare: a file inside a nested repository
     * is that repository's, whatever its path looks like.
     *
     * @param \Closure(string): string $subject the subject for the file's relative path
     * @param array<string, mixed>|null $workspace the checkpoint taken before the turn, for the dirty commit
     * @return array{ok: bool, sha: ?string, subject: string, paths: list<string>, snapshot: ?string, reason: string}|null
     */
    public function commitEdit(string $absolutePath, \Closure $subject, ?array $workspace): ?array
    {
        $top = $this->topLevel();
        $real = realpath($absolutePath);
        if ($top === null || $real === false || !is_file($real)) {
            return null;
        }
        $here = $this->git(\dirname($real));
        $fileTop = $here->run('rev-parse', '--show-toplevel');
        $prefix = $here->run('rev-parse', '--show-prefix');
        if (!$fileTop['ok'] || !$prefix['ok'] || trim($fileTop['stdout']) !== $top) {
            return null;
        }
        $topReal = $top;
        $relative = trim($prefix['stdout']) . basename($real);
        $subject = $subject($relative);

        $git = $this->git($topReal);
        if ($git->run('check-ignore', '-q', '--', $relative)['ok']) {
            return null;
        }
        if ($this->dirtyAmong($topReal, [$relative]) === []) {
            return null;
        }

        $snapshot = null;
        if ($workspace !== null && ($workspace['kind'] ?? null) === 'repo' && WorkspaceCheckpointer::isCaptured($workspace)) {
            $snapped = $this->snapshotUserChanges($topReal, [$relative], $workspace);
            if (\is_string($snapped)) {
                return self::failure([$relative], $subject, $snapped);
            }
            $snapshot = $snapped['sha'];
        } elseif ($this->trackedAndCommitted($git, $relative)) {
            // No checkpoint says what the user had before this turn, so the
            // file's changes cannot be split into theirs and the model's.
            return self::failure([$relative], $subject, 'no checkpoint of the files was taken before this turn, so your own changes to it could not be kept apart');
        }

        $outcome = $this->commit([$relative], $subject);

        return $outcome + ['snapshot' => $snapshot];
    }

    // -------------------------------------------------------------------------
    // The commit itself
    // -------------------------------------------------------------------------

    /**
     * Commit $paths (relative to the top level) as they are in the work tree,
     * with $subject and the trailer, running the user's hooks. On failure the
     * paths are unstaged again and the reason is the hook's or git's own words.
     *
     * @param list<string> $paths
     * @return array{ok: bool, sha: ?string, subject: string, paths: list<string>, reason: string}
     */
    public function commit(array $paths, string $subject, string $kind = 'edit'): array
    {
        $top = $this->topLevel();
        if ($top === null) {
            return self::failure($paths, $subject, 'the project is not a git work tree');
        }
        $git = $this->git($top);

        $add = $git->run('add', '-A', '--', ...$paths);
        if (!$add['ok']) {
            return self::failure($paths, $subject, 'git add failed: ' . self::why($add));
        }

        $message = ['-m', $subject];
        if ($this->trailer !== '') {
            $message = [...$message, '-m', $this->trailer];
        }
        $commit = $git->withTimeout(self::COMMIT_TIMEOUT_SECONDS)->run(...[
            '-c', 'core.hooksPath=' . $this->hooksPath($git, $top),
            'commit', '--quiet', ...$this->authorArgs($git), ...$message, '--', ...$paths,
        ]);
        if (!$commit['ok']) {
            $git->run('reset', '-q', '--', ...$paths);

            return self::failure($paths, $subject, 'git commit failed: ' . self::why($commit));
        }

        $sha = $this->headSha($git);
        if ($sha !== null) {
            $this->record($git, $top, $sha, $kind, $subject);
        }

        return ['ok' => true, 'sha' => $sha, 'subject' => $subject, 'paths' => $paths, 'reason' => ''];
    }

    /**
     * Aider's dirty commit: of $paths, those the user had changed before the
     * turn (the checkpoint differs from the HEAD it was taken on) and that no
     * commit has touched since, committed as the user had them, in a commit of
     * their own made through a scratch index — the user's real index is not
     * touched. Null `sha` when there was nothing of the user's to keep apart.
     *
     * @param list<string> $paths
     * @param array<string, mixed> $workspace
     * @return array{sha: ?string}|string
     */
    private function snapshotUserChanges(string $top, array $paths, array $workspace): array|string
    {
        $git = $this->git($top);
        $sha = (string) ($workspace['sha'] ?? '');
        $base = \is_string($workspace['base'] ?? null) && $workspace['base'] !== '' ? $workspace['base'] : null;

        $user = $this->entries($git, $sha, $paths);
        if (($workspace['layout'] ?? null) === 'stash' && $git->run('rev-parse', '--verify', '-q', $sha . '^3')['ok']) {
            $user += $this->entries($git, $sha . '^3', array_values(array_diff($paths, array_keys($user))));
        }
        $before = $base === null ? [] : $this->entries($git, $base, $paths);
        $head = $this->headSha($git) === null ? [] : $this->entries($git, 'HEAD', $paths);

        $info = '';
        foreach ($paths as $path) {
            $theirs = $user[$path] ?? null;
            $was = $before[$path] ?? null;
            if ($theirs === $was || ($head[$path] ?? null) !== $was) {
                continue;
            }
            $info .= ($theirs ?? '0 ' . self::NULL_SHA) . "\t" . $path . "\0";
        }
        if ($info === '') {
            return ['sha' => null];
        }

        $scratch = @tempnam(sys_get_temp_dir(), 'sc-autocommit-');
        if ($scratch === false) {
            return 'could not create a scratch index';
        }
        @unlink($scratch);
        try {
            $staged = $git->withIndexFile($scratch);
            $read = $head === [] && $this->headSha($git) === null ? $staged->run('read-tree', '--empty') : $staged->run('read-tree', 'HEAD');
            if (!$read['ok'] || !$staged->runWithInput($info, 'update-index', '-z', '--index-info')['ok']) {
                return 'could not stage your earlier changes in a scratch index';
            }
            $commit = $staged->withTimeout(self::COMMIT_TIMEOUT_SECONDS)->run(...[
                '-c', 'core.hooksPath=' . $this->hooksPath($git, $top),
                'commit', '--quiet', ...$this->authorArgs($git), '-m', self::SNAPSHOT_SUBJECT,
            ]);
            if (!$commit['ok']) {
                return 'committing your earlier changes first failed: ' . self::why($commit);
            }
        } finally {
            @unlink($scratch);
            @unlink($scratch . '.lock');
        }

        $snapshot = $this->headSha($git);
        if ($snapshot !== null) {
            $this->record($git, $top, $snapshot, 'snapshot', self::SNAPSHOT_SUBJECT);
        }

        return ['sha' => $snapshot];
    }

    // -------------------------------------------------------------------------
    // /undo
    // -------------------------------------------------------------------------

    /** Whether this session has made an auto-commit in this repository. */
    public function hasCommits(): bool
    {
        $top = $this->topLevel();

        return $top !== null && $this->records($this->git($top), $top) !== [];
    }

    /**
     * Revert the last commit if this session made it — Aider's `/undo`:
     * `git checkout HEAD~1 -- <files>` and `git reset --soft HEAD~1`, so the
     * files and the index go back and nothing else moves. A snapshot of the
     * user's own changes is un-committed instead: `reset --soft` then unstage,
     * so their changes are back where they were — uncommitted, in the files.
     *
     * Refused (`refusal` names which) when HEAD is not this session's, is the
     * root commit or a merge, a file it changed has uncommitted changes now,
     * a file it changed did not exist before it, or it is already on a remote.
     *
     * @return array{ok: bool, refusal: ?string, reason: string, sha: ?string, subject: string, kind: string, files: list<string>}
     */
    public function undo(): array
    {
        $top = $this->topLevel();
        if ($top === null) {
            return self::refused(self::REFUSED_NOT_OURS, 'the project is not a git work tree');
        }
        $git = $this->git($top);
        $head = $this->headSha($git);
        $records = $this->records($git, $top);
        $record = $head === null ? null : ($records[$head] ?? null);
        if ($head === null || $record === null) {
            return self::refused(self::REFUSED_NOT_OURS, 'the last commit was not made by sugar-crush in this session');
        }
        $short = substr($head, 0, 7);
        $subject = (string) $record['subject'];

        $parents = $git->run('rev-list', '--parents', '-n', '1', 'HEAD');
        $parentCount = \count(preg_split('/\s+/', trim($parents['stdout'])) ?: []) - 1;
        if (!$parents['ok'] || $parentCount < 1) {
            return self::refused(self::REFUSED_ROOT, "{$short} is the repository's first commit; there is nothing before it to go back to", $head, $subject);
        }
        if ($parentCount > 1) {
            return self::refused(self::REFUSED_MERGE, "{$short} is a merge commit", $head, $subject);
        }

        $listed = $git->run('diff-tree', '--no-commit-id', '--name-only', '-r', '-z', 'HEAD');
        $files = array_values(array_filter(explode("\0", $listed['stdout']), static fn (string $f): bool => $f !== ''));
        if (!$listed['ok'] || $files === []) {
            return self::refused(self::REFUSED_NOT_OURS, "could not list the files {$short} changed", $head, $subject);
        }

        $dirty = $this->dirtyAmong($top, $files);
        if ($dirty !== []) {
            return self::refused(self::REFUSED_DIRTY, 'these files have uncommitted changes now: ' . implode(', ', $dirty) . ' — commit or stash them before undoing', $head, $subject, $files);
        }

        if ($record['kind'] !== 'snapshot') {
            foreach ($files as $file) {
                if (!$git->run('cat-file', '-e', 'HEAD~1:' . $file)['ok']) {
                    return self::refused(self::REFUSED_ABSENT_IN_PARENT, "{$file} did not exist before {$short}, so it cannot be put back safely", $head, $subject, $files);
                }
            }
        }

        $remote = $git->run('branch', '-r', '--contains', 'HEAD');
        if ($remote['ok'] && trim($remote['stdout']) !== '') {
            return self::refused(self::REFUSED_PUSHED, "{$short} is already on " . trim(strtok(trim($remote['stdout']), "\n") ?: 'a remote') . '; undoing it would rewrite pushed history', $head, $subject, $files);
        }

        if ($record['kind'] === 'snapshot') {
            $reset = $git->run('reset', '--soft', 'HEAD~1');
            $ok = $reset['ok'] && $git->run('reset', '-q', '--', ...$files)['ok'];
        } else {
            $checkout = $git->run('checkout', 'HEAD~1', '--', ...$files);
            $ok = $checkout['ok'] && $git->run('reset', '--soft', 'HEAD~1')['ok'];
        }
        if (!$ok) {
            return ['ok' => false, 'refusal' => null, 'reason' => "git could not revert {$short}", 'sha' => $head, 'subject' => $subject, 'kind' => (string) $record['kind'], 'files' => $files];
        }
        $this->forget($git, $top, $head);

        return ['ok' => true, 'refusal' => null, 'reason' => '', 'sha' => $head, 'subject' => $subject, 'kind' => (string) $record['kind'], 'files' => $files];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function git(string $cwd): GitRunner
    {
        return GitRunner::new($cwd)->withTimeout(self::READ_TIMEOUT_SECONDS);
    }

    private function headSha(GitRunner $git): ?string
    {
        $head = $git->run('rev-parse', '--verify', '-q', 'HEAD');

        return $head['ok'] && trim($head['stdout']) !== '' ? trim($head['stdout']) : null;
    }

    /**
     * Of $paths, those whose work-tree or index state differs from HEAD, in
     * $paths' order.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function dirtyAmong(string $top, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $status = $this->git($top)->run('status', '--porcelain=v1', '-z', '--untracked-files=all', '--', ...$paths);
        if (!$status['ok']) {
            return [];
        }
        $dirty = [];
        $fields = explode("\0", $status['stdout']);
        for ($i = 0; $i < \count($fields); $i++) {
            $entry = $fields[$i];
            if (\strlen($entry) < 4) {
                continue;
            }
            $dirty[substr($entry, 3)] = true;
            // A rename/copy entry is followed by its source path.
            if ($entry[0] === 'R' || $entry[0] === 'C') {
                $i++;
            }
        }

        return array_values(array_filter($paths, static fn (string $path): bool => isset($dirty[$path])));
    }

    /** Whether $path is tracked and committed in HEAD. */
    private function trackedAndCommitted(GitRunner $git, string $path): bool
    {
        return $git->run('cat-file', '-e', 'HEAD:' . $path)['ok'];
    }

    /**
     * `mode blob` per path in $treeish, for the $paths it has.
     *
     * @param list<string> $paths
     * @return array<string, string>
     */
    private function entries(GitRunner $git, string $treeish, array $paths): array
    {
        if ($paths === [] || $treeish === '') {
            return [];
        }
        $listed = $git->run('ls-tree', '-r', '-z', '--full-tree', $treeish, '--', ...$paths);
        if (!$listed['ok']) {
            return [];
        }
        $entries = [];
        foreach (explode("\0", $listed['stdout']) as $line) {
            if (preg_match('/^(\d+) blob ([0-9a-f]+)\t(.+)$/s', $line, $m) === 1) {
                $entries[$m[3]] = $m[1] . ' ' . $m[2];
            }
        }

        return $entries;
    }

    /**
     * The patch of $paths against HEAD for the commit-message model, with the
     * head of each file git does not track yet (a new file has no patch).
     *
     * @param list<string> $paths
     */
    private function diff(string $top, array $paths): string
    {
        $git = $this->git($top);
        $text = $this->headSha($git) === null
            ? ''
            : $git->capture(CommitMessageWriter::MAX_DIFF_BYTES * 2, 'diff', '--no-color', '--no-ext-diff', 'HEAD', '--', ...$paths)['stdout'];

        $tracked = $git->run('ls-files', '-z', '--', ...$paths);
        $known = array_flip(array_filter(explode("\0", $tracked['stdout']), static fn (string $f): bool => $f !== ''));
        foreach ($paths as $path) {
            if (isset($known[$path])) {
                continue;
            }
            // Through git, not a file read: a new file that is a symlink is
            // shown as the link it is, never as whatever it points at.
            $text .= "\nnew file {$path}\n" . $git->capture(4096, 'diff', '--no-index', '--no-color', '--no-ext-diff', '--', '/dev/null', $path)['stdout'];
        }

        return $text;
    }

    /**
     * The hooks directory the user's own git configuration resolves to — the
     * last `core.hooksPath` from a config FILE (GitRunner's own command-line
     * pin is skipped), else `<common git dir>/hooks`.
     */
    private function hooksPath(GitRunner $git, string $top): string
    {
        $configured = $git->run('config', '--show-origin', '--get-all', 'core.hooksPath');
        $path = null;
        foreach (explode("\n", $configured['stdout']) as $line) {
            [$origin, $value] = array_pad(explode("\t", $line, 2), 2, '');
            if ($origin === '' || str_starts_with($origin, 'command line:')) {
                continue;
            }
            $path = $value;
        }
        if ($path !== null && $path !== '') {
            return $path;
        }

        $common = trim($git->run('rev-parse', '--git-common-dir')['stdout']);
        if ($common === '') {
            $common = '.git';
        }

        return (str_starts_with($common, '/') ? $common : $top . '/' . $common) . '/hooks';
    }

    /**
     * `--author` for the user's configured identity, or nothing when it is
     * incomplete (git then uses the runner's fixed one).
     *
     * @return list<string>
     */
    private function authorArgs(GitRunner $git): array
    {
        $name = trim($git->run('config', '--get', 'user.name')['stdout']);
        $email = trim($git->run('config', '--get', 'user.email')['stdout']);

        return $name !== '' && $email !== '' ? ['--author=' . $name . ' <' . $email . '>'] : [];
    }

    private function recordFile(GitRunner $git, string $top): ?string
    {
        $path = trim($git->run('rev-parse', '--git-path', self::RECORD_PATH)['stdout']);
        if ($path === '') {
            return null;
        }

        return str_starts_with($path, '/') ? $path : $top . '/' . $path;
    }

    private function record(GitRunner $git, string $top, string $sha, string $kind, string $subject): void
    {
        $file = $this->recordFile($git, $top);
        if ($file === null || (!is_dir(\dirname($file)) && !@mkdir(\dirname($file), 0o700, true))) {
            return;
        }
        $line = json_encode(['sha' => $sha, 'session' => $this->sessionId, 'kind' => $kind, 'subject' => $subject, 'at' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (\is_array($lines) && \count($lines) > self::MAX_RECORDS) {
            @file_put_contents($file, implode("\n", \array_slice($lines, -self::MAX_RECORDS)) . "\n", LOCK_EX);
        }
    }

    /**
     * This session's commits, sha => record.
     *
     * @return array<string, array{kind: string, subject: string}>
     */
    private function records(GitRunner $git, string $top): array
    {
        $file = $this->recordFile($git, $top);
        $lines = $file === null ? false : @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines)) {
            return [];
        }
        $records = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (\is_array($row) && ($row['session'] ?? null) === $this->sessionId && \is_string($row['sha'] ?? null)) {
                $records[$row['sha']] = [
                    'kind' => \is_string($row['kind'] ?? null) ? $row['kind'] : 'edit',
                    'subject' => \is_string($row['subject'] ?? null) ? $row['subject'] : '',
                ];
            }
        }

        return $records;
    }

    private function forget(GitRunner $git, string $top, string $sha): void
    {
        $file = $this->recordFile($git, $top);
        $lines = $file === null ? false : @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines) || $file === null) {
            return;
        }
        $kept = array_filter($lines, static fn (string $line): bool => !str_contains($line, '"sha":"' . $sha . '"'));
        @file_put_contents($file, $kept === [] ? '' : implode("\n", $kept) . "\n", LOCK_EX);
    }

    /** @param array{stderr: string, stdout: string, timedOut: bool} $result */
    private static function why(array $result): string
    {
        if ($result['timedOut']) {
            return 'it ran out of time';
        }
        $text = trim($result['stderr']) !== '' ? $result['stderr'] : $result['stdout'];
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text === '' ? 'no reason given' : mb_substr($text, 0, 400);
    }

    /**
     * @param list<string> $paths
     * @return array{ok: bool, sha: ?string, subject: string, paths: list<string>, snapshot: ?string, reason: string}
     */
    private static function failure(array $paths, string $subject, string $reason): array
    {
        return ['ok' => false, 'sha' => null, 'subject' => $subject, 'paths' => $paths, 'snapshot' => null, 'reason' => $reason];
    }

    /**
     * @param list<string> $files
     * @return array{ok: bool, refusal: ?string, reason: string, sha: ?string, subject: string, kind: string, files: list<string>}
     */
    private static function refused(string $refusal, string $reason, ?string $sha = null, string $subject = '', array $files = []): array
    {
        return ['ok' => false, 'refusal' => $refusal, 'reason' => $reason, 'sha' => $sha, 'subject' => $subject, 'kind' => '', 'files' => $files];
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
