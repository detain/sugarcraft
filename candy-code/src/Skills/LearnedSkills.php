<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Code\Skills;

use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Code\Support\AtomicFileWriter;

/**
 * The versioned store of LEARNED skills — the live half of C-EXT-3.e, under
 * owner ruling R-EXT-2 (plan §6.3): a model-written skill goes live
 * immediately and every landing is announced with an undo door, so the
 * history kept here is what makes that undo real.
 *
 * WHAT IT IS. A thin store over the user tier {@see ProposedSkills::accept()}
 * already writes: `~/.candy-code/skills/<name>/SKILL.md` is the live file,
 * and beside it the store keeps `<name>/history/<n>.md` — every byte this
 * store ever wrote, numbered upward. It wraps {@see ProposedSkills} for the
 * three laws a model's own text must not be trusted to keep: the name
 * sanitiser ({@see ProposedSkills::sanitizeName()}, so a name can never be a
 * path), the size caps ({@see ProposedSkills::MAX_DESCRIPTION_CHARS} and
 * {@see ProposedSkills::MAX_BODY_BYTES}, refused rather than cut), and the
 * {@see SecretRedactor} pass over name, description and body. The rendered
 * file carries the same frontmatter shape `ProposedSkills::render()` writes,
 * so an accepted draft and a workshop skill parse identically.
 *
 * WHY LIVE-IN-STANT (R-EXT-2). The alternative — draft behind accept — was
 * the review queue's answer for the UNATTENDED dream pass, whose writer sees
 * no one. The workshop tool is different in kind: it runs inside a live turn,
 * its calls ride the Write-class ask-gate (default mode prompts the human
 * before any write), and its result lands as an announcement the user reads
 * the same second, naming `/skills undo <name>`. Consent happens at the call,
 * disclosure happens at the settle, and the undo is not a deletion but a
 * restore to the previous saved revision — which is exactly what the history
 * directory is for. The dream pass stays the review leg
 * (`memory.dreamProposeSkills` → {@see ProposedSkills}); no new trigger.
 *
 * THE SHADOWING FLOOR. Creating a learned skill whose name a lower-precedence
 * built-in, project or bundle skill already carries would shadow it in every
 * later session ({@see SkillOrigin::precedence()} puts `user` above
 * `built-in`). Refused, as accept() refuses, by the loader's own manifests —
 * and patching is user-tier-only by construction: it walks `history/`, never
 * another tier's file.
 *
 * BOUNDED REVISIONS, ARCHIVED. The active window holds the newest
 * {@see MAX_REVISIONS} files; an overflow moves the oldest WHOLE file to
 * `history/archive/` rather than deleting it — the window a `undo` can walk
 * is bounded, the record is not. An archived revision is visible in
 * `/skills history` and is outside the undo door by the same law that bounds
 * the window.
 *
 * DISCIPLINE OF THE WRITE. Same shape as the draft store it wraps: the home
 * must be this user's own ({@see HomeDirectory::owned()} through the wrapped
 * store — a foreign or world-writable home gets refusals, not a shared tree);
 * every directory the store creates is 0700 and every file 0600; a link at
 * the skill directory, the SKILL.md, the history directory or any revision is
 * refused before it is read or written; the revision file is always written
 * BEFORE the live file it versions, so no live state ever exists that the
 * history does not already hold; and both writes are
 * {@see AtomicFileWriter} renames, so a killed process leaves the old bytes,
 * never half a skill.
 */
final class LearnedSkills
{
    /** The per-skill revisions directory, under the skill's own directory. */
    public const HISTORY_SUBDIR = 'history';

    /** Where overflow revisions are kept, under the history directory. */
    public const ARCHIVE_SUBDIR = 'history/archive';

    /** The largest active window `/skills undo` can walk back through. */
    public const MAX_REVISIONS = 8;

    /** A stored revision's readable cap: the body cap plus a wide frontmatter margin. */
    public const MAX_REVISION_BYTES = ProposedSkills::MAX_BODY_BYTES + 4096;

    /** Revision file names are exactly `<n>.md` for one or more digits. */
    private const REVISION_PATTERN = '/^([0-9]+)\.md$/';

    private readonly ProposedSkills $drafts;

    private readonly SecretRedactor $redactor;

    private function __construct(
        private readonly ?string $home,
    ) {
        // The wrap is real, not decorative: the live root, the name law and
        // the caps all ANSWER THROUGH the draft store this store versions.
        $this->drafts = ProposedSkills::new($home);
        $this->redactor = SecretRedactor::new();
    }

    /**
     * The store under $home (null: {@see HomeDirectory::owned()}), or with no
     * owned home one whose every write refuses — the draft store's own law.
     */
    public static function new(?string $home = null): self
    {
        return new self($home === null ? null : (rtrim($home, '/') ?: '/'));
    }

    /** `~/.candy-code/skills`, or null with no owned home. */
    public function liveRoot(): ?string
    {
        return $this->drafts->liveRoot();
    }

    /** Whether the user tier holds $name as a plain (non-link) SKILL.md. */
    public function isLive(string $name): bool
    {
        $root = $this->liveRoot();
        if ($root === null || !ProposedSkills::isDraftName($name)) {
            return false;
        }

        return self::plainFile($root . '/' . $name . '/SKILL.md');
    }

    /**
     * Where a learned skill's live file is (or was just) written — the path
     * the R-EXT-2 announcement names. Null with no owned home; a name that
     * never survived the sanitiser has no path to name.
     */
    public function liveSkillPath(string $name): ?string
    {
        $root = $this->liveRoot();
        if ($root === null || !ProposedSkills::isDraftName($name)) {
            return null;
        }

        return $root . '/' . $name . '/SKILL.md';
    }

    /**
     * Write $proposal as a NEW live learned skill with saved revision 1, and
     * answer the revision.
     *
     * @throws \RuntimeException when there is no owned home, the name is
     *         already a skill in any tier (a user-tier one is patched, never
     *         re-created; any other would be shadowed), or a write fails
     * @throws \InvalidArgumentException when the name sanitises to nothing, a
     *         field is empty or over its cap, or the file does not parse back
     */
    public function create(SkillProposal $proposal): LearnedSkillRevision
    {
        $name = self::fieldName($this->redactor->redact($proposal->name));

        $description = $this->fieldText($proposal->description, $name, 'description', ProposedSkills::MAX_DESCRIPTION_CHARS, false);
        $body = $this->fieldText($proposal->body, $name, 'body', ProposedSkills::MAX_BODY_BYTES, true);

        $root = $this->requireRoot();
        $dir = $root . '/' . $name;
        clearstatcache(true, $dir);
        if (is_link($dir) || file_exists($dir)) {
            throw new \RuntimeException(sprintf(
                'a skill named "%s" exists already in the user tier; patch it instead (`action: patch`)',
                $name,
            ));
        }
        $this->refuseShadowed($name, $root);

        $content = self::render($name, $description, $body);
        // Parsed back by the loader that will read it, as the draft store's
        // own door insists: an announced skill must be a loadable one.
        Skill::parse($content, $name);

        $this->ensureDir($root);
        $this->ensureDir($dir);

        return $this->land($name, $content, 1);
    }

    /**
     * Patch the learned skill $name: fields left null keep what the newest
     * saved revision holds; the merged file lands as revision N+1 and becomes
     * the live SKILL.md. Only user-tier skills the store itself wrote (their
     * history carries at least one revision) can be patched — a skill that
     * arrived through `/skills accept` or a hand-written file is refused,
     * because this store would be overwriting bytes it never versioned.
     *
     * @throws \RuntimeException when the skill is not a learned one, or a write fails
     * @throws \InvalidArgumentException when the name is not a draft name or a
     *         supplied field is empty, over cap, or the merge leaves nothing
     */
    public function patch(string $name, ?string $description, ?string $body): LearnedSkillRevision
    {
        $name = self::fieldName($name);
        $root = $this->requireRoot();

        $current = $this->headRevision($root . '/' . $name);
        if ($current === null) {
            throw new \RuntimeException(sprintf(
                'no learned skill named "%s" to patch — only skills the SkillWorkshop wrote carry saved revisions; `/skills history %s` shows what exists',
                $name,
                $name,
            ));
        }

        $merged = Skill::parse($current['content'], $name);
        $newDescription = $description === null
            ? $merged->description
            : $this->fieldText($description, $name, 'description', ProposedSkills::MAX_DESCRIPTION_CHARS, false);
        $newBody = $body === null
            ? $merged->content
            : $this->fieldText($body, $name, 'body', ProposedSkills::MAX_BODY_BYTES, true);

        $content = self::render($name, $newDescription, $newBody);
        Skill::parse($content, $name);

        return $this->land($name, $content, $current['number'] + 1);
    }

    /**
     * The undo door R-EXT-2's announcement names. Revert $name to the state
     * its saved history holds:
     *
     *  - newest revision N > 1: restore revision N−1's bytes as SKILL.md and
     *    drop revision N (the window shrinks by the undone head);
     *  - sole revision 1: the skill only ever existed as a learned one —
     *    remove SKILL.md and the history; the skill directory is pruned only
     *    when empty, so files the user added beside it are never deleted. A
     *    removal the filesystem denies is RAISED, naming what survived —
     *    never reported as a removal whose bytes are still sitting there;
     *  - no saved revision: refused (a hand-written or accepted-draft skill
     *    has no undo through this store).
     *
     * @return array{action: string, name: string, revision: int, path: string, residue: ?string}
     *
     * @throws \RuntimeException when the skill has nothing saved to undo, or
     *         when one of the head's files cannot be removed (the refusal
     *         names exactly what survived the half-applied undo)
     */
    public function undo(string $name): array
    {
        $name = self::fieldName($name);
        $root = $this->requireRoot();
        $dir = $root . '/' . $name;

        $numbers = $this->revisionNumbers($dir);
        if ($numbers === []) {
            throw new \RuntimeException(sprintf(
                'no saved revision to undo for "%s" — the SkillWorkshop records history; skills that arrived another way have none',
                $name,
            ));
        }
        $head = (int) max($numbers);

        if ($head === 1) {
            // Removal walks the write law in reverse: the live file goes
            // FIRST, while the history still covers its bytes, then the
            // revision it was copied from. Each leg checks its own result —
            // a denial is raised with the surviving path named, because a
            // false "removed" would mislabel every later history read, and
            // the file_exists() re-read keeps the check honest for a file
            // that was already gone (a retry after a raised refusal, or a
            // live file the user deleted by hand).
            $file = $dir . '/SKILL.md';
            if (@unlink($file) === false && file_exists($file)) {
                throw new \RuntimeException(sprintf(
                    'cannot remove the live file of "%s" to undo it — %s is still there and nothing was removed',
                    $name,
                    $file,
                ));
            }
            $revision = $dir . '/' . self::HISTORY_SUBDIR . '/1.md';
            if (@unlink($revision) === false && file_exists($revision)) {
                throw new \RuntimeException(sprintf(
                    'cannot remove revision 1 of "%s" to undo it — the live file is gone but the saved revision survives at %s; run undo again once the block clears',
                    $name,
                    $revision,
                ));
            }
            @rmdir($dir . '/' . self::HISTORY_SUBDIR);
            $residue = null;
            if (!@rmdir($dir)) {
                // Both recorded files are gone by this point; a directory
                // that survives holds only what the store never wrote, so
                // the report names the residue rather than the unlink legs
                // needing one.
                clearstatcache(true, $dir);
                $residue = is_dir($dir) ? $dir : null;
            }

            return ['action' => 'removed', 'name' => $name, 'revision' => 0, 'path' => $file, 'residue' => $residue];
        }

        $restore = $this->readRevision($dir . '/' . self::HISTORY_SUBDIR . '/' . ($head - 1) . '.md');
        if ($restore === null) {
            throw new \RuntimeException(sprintf('revision %d of "%s" cannot be read to undo into it', $head - 1, $name));
        }
        $this->ensureDir($dir);
        AtomicFileWriter::write($dir . '/SKILL.md', $restore, 0600);
        @unlink($dir . '/' . self::HISTORY_SUBDIR . '/' . $head . '.md');

        return ['action' => 'restored', 'name' => $name, 'revision' => $head - 1, 'path' => $dir . '/SKILL.md', 'residue' => null];
    }

    /**
     * What the history directory holds for $name: the live flag, each active
     * revision's number, bytes and mtime (ascending), and how many older
     * revisions sit in the archive. Content is never read here — a listing
     * that slurped every body would be the walk this store refuses.
     *
     * @return array{name: string, live: bool, archived: int, revisions: list<array{number: int, bytes: int, modified: int}>}
     */
    public function history(string $name): array
    {
        $name = self::fieldName($name);
        $root = $this->requireRoot();
        $dir = $root . '/' . $name;

        $rows = [];
        foreach ($this->revisionNumbers($dir) as $number) {
            $file = $dir . '/' . self::HISTORY_SUBDIR . '/' . $number . '.md';
            $rows[] = [
                'number' => $number,
                'bytes' => (int) @filesize($file),
                'modified' => (int) @filemtime($file),
            ];
        }
        $archivedDir = $dir . '/' . self::ARCHIVE_SUBDIR;
        $archived = is_link($archivedDir) ? 0 : \count($this->listing($archivedDir));

        return ['name' => $name, 'live' => $this->isLive($name), 'archived' => $archived, 'revisions' => $rows];
    }

    /** The revision count a refusal or announcement may quote without walking twice. */
    public function revisionCount(string $name): int
    {
        $root = $this->liveRoot();

        return $root === null ? 0 : \count($this->revisionNumbers($root . '/' . $name));
    }

    // ------------------------------------------------------------------ guts

    /**
     * Land one rendered file: revision first, then the live SKILL.md, then the
     * window trim. The order is the law stated in the class header — no live
     * bytes exist before the history holds them.
     */
    private function land(string $name, string $content, int $number): LearnedSkillRevision
    {
        $root = $this->requireRoot();
        $dir = $root . '/' . $name;
        $history = $dir . '/' . self::HISTORY_SUBDIR;
        $this->ensureDir($dir);
        $this->ensureDir($history);

        $revisionPath = $history . '/' . $number . '.md';
        AtomicFileWriter::write($revisionPath, $content, 0600);
        AtomicFileWriter::write($dir . '/SKILL.md', $content, 0600);

        // Bound the window: everything older than the newest MAX_REVISIONS
        // numbers moves whole into the archive, never merged, never deleted.
        $numbers = $this->revisionNumbers($dir);
        if (\count($numbers) > self::MAX_REVISIONS) {
            $archive = $dir . '/' . self::ARCHIVE_SUBDIR;
            $this->ensureDir($archive);
            foreach (\array_slice($numbers, 0, \count($numbers) - self::MAX_REVISIONS) as $old) {
                @rename($history . '/' . $old . '.md', $archive . '/' . $old . '.md');
            }
        }

        return LearnedSkillRevision::new($name, $number, $revisionPath, \strlen($content));
    }

    /** $raw redacted and sanitised to a draft name; nothing left is refused. */
    private static function fieldName(string $raw): string
    {
        $name = str_replace("\0", '', $raw);
        $sanitised = ProposedSkills::sanitizeName($name);
        if ($sanitised === null) {
            throw new \InvalidArgumentException('the skill name holds nothing a file name can carry');
        }

        return $sanitised;
    }

    /**
     * The proposed-skills field law, kept in one door: NULs out, whitespace
     * runs to single spaces (a body keeps its line structure — newlines are
     * whitespace, so bodies skip that fold), secret-blank, then refuse empty
     * and refuse over cap. Mirrors the draft store's checks so the two trees
     * accept exactly the same bytes.
     */
    private function fieldText(string $raw, string $name, string $what, int $cap, bool $multiline): string
    {
        $clean = $this->redactor->redact(str_replace("\0", '', $raw));
        $clean = mb_scrub($clean, 'UTF-8');
        $clean = $multiline
            ? trim($clean)
            : trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        if ($clean === '') {
            throw new \InvalidArgumentException(sprintf('the skill "%s" was given an empty %s', $name, $what));
        }
        $size = $multiline ? \strlen($clean) : mb_strlen($clean);
        if ($size > $cap) {
            throw new \InvalidArgumentException(sprintf(
                'the skill "%s" has a %s over %s %s',
                $name,
                $what,
                number_format($cap),
                $multiline ? 'bytes' : 'characters',
            ));
        }

        return $clean;
    }

    /**
     * The rendered SKILL.md — the same one JSON-frontmatter shape the draft
     * store renders, restated here rather than reached through it because
     * `render()` is private by the draft store's own design; the law ("a JSON
     * string is a valid YAML double-quoted scalar") is one sentence for both.
     */
    private static function render(string $name, string $description, string $body): string
    {
        return "---\nname: {$name}\ndescription: "
            . json_encode($description, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            . "\n---\n\n" . $body . "\n";
    }

    /**
     * Refuse a create whose name the loader already resolves from another
     * tier: a built-in, project or bundle skill shadowed by a learned one is
     * the persistence vector R-EXT-2's risks row names, and `user` outranks
     * `built-in` in {@see SkillOrigin::precedence()}.
     */
    private function refuseShadowed(string $name, string $root): void
    {
        $loader = new SkillLoader(false);
        foreach ($loader->loadAllManifests('.') as $rowName => $row) {
            if ($rowName !== $name) {
                continue;
            }
            $where = $row['origin']->value;
            if ($where === SkillOrigin::User->value && (string) $row['sourcePath'] === $root . '/' . $name . '/SKILL.md') {
                continue; // the user tier's own file — the occupied check above already spoke to it
            }

            throw new \RuntimeException(sprintf(
                'a %s skill named "%s" exists already at %s; a learned skill must not shadow it',
                $where,
                $name,
                $row['sourcePath'],
            ));
        }
    }

    /**
     * The newest saved revision as [number, content], or null when the window
     * is empty. A present-but-unreadable newest file THROWS rather than falls
     * back: patching over bytes the store cannot see would fabricate history.
     *
     * @return ?array{number: int, content: string}
     */
    private function headRevision(string $dir): ?array
    {
        $numbers = $this->revisionNumbers($dir);
        if ($numbers === []) {
            return null;
        }
        $head = (int) max($numbers);
        $content = $this->readRevision($dir . '/' . self::HISTORY_SUBDIR . '/' . $head . '.md');
        if ($content === null) {
            throw new \RuntimeException(sprintf('the newest saved revision in "%s" cannot be read', $dir));
        }

        return ['number' => $head, 'content' => $content];
    }

    /** Read one revision file whole, bounded, links refused; null when unreadable. */
    private function readRevision(string $path): ?string
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        if ((int) @filesize($path) > self::MAX_REVISION_BYTES) {
            return null;
        }
        $bytes = @file_get_contents($path, false, null, 0, self::MAX_REVISION_BYTES + 1);

        return $bytes === false || \strlen($bytes) > self::MAX_REVISION_BYTES ? null : $bytes;
    }

    /**
     * The active window's revision numbers, ascending. A non-numeric or
     * non-`.md` entry is not a revision and is ignored; a linked revision file
     * is not one either — the window never reads through a link.
     *
     * @return list<int>
     */
    private function revisionNumbers(string $dir): array
    {
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            // Never descend through a linked skill directory; a planted link
            // is refused the same way ensureDir refuses to create through one.
            return [];
        }
        $history = $dir . '/' . self::HISTORY_SUBDIR;
        $numbers = [];
        foreach ($this->listing($history) as $entry) {
            if (preg_match(self::REVISION_PATTERN, $entry, $m) !== 1) {
                continue;
            }
            $path = $history . '/' . $entry;
            clearstatcache(true, $path);
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $numbers[] = (int) $m[1];
        }
        sort($numbers);

        return $numbers;
    }

    /**
     * Entry names of one directory, or empty: a lister whose root or one
     * ancestor is a link reads nothing at all, and the callers judge every
     * entry they act on by its own path.
     *
     * @return list<string>
     */
    private function listing(string $dir): array
    {
        // No containment compare is needed because none can be crossed: the
        // prefix is requireRoot()'s plain non-link directory and the name
        // between prefix and directory has been through the draft store's
        // sanitiser, which can never emit a slash, a dot or a NUL. What a
        // hostile hand can still plant INSIDE the tree is a linked directory
        // — and that is exactly the check below.
        clearstatcache(true, $dir);
        if (is_link($dir) || !is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(
            @scandir($dir) ?: [],
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
    }

    /** Create one directory owner-only, refusing a link standing in its place. */
    private function ensureDir(string $dir): void
    {
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            throw new \RuntimeException("{$dir} is a symlink; nothing is written through it");
        }
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("could not create {$dir}");
        }
        @chmod($dir, 0700);
    }

    private function requireRoot(): string
    {
        $root = $this->liveRoot();
        if ($root === null) {
            throw new \RuntimeException('there is no home directory this user owns to keep learned skills in');
        }
        clearstatcache(true, $root);
        if (is_link($root)) {
            throw new \RuntimeException("{$root} is a symlink; no learned skill is written through it");
        }

        return $root;
    }

    /** Whether $path is a real file behind plain directories all the way. */
    private static function plainFile(string $path): bool
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            return false;
        }
        $dir = \dirname($path);

        return !is_link($dir);
    }
}
