<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Memory\SecretRedactor;
use SugarCraft\Crush\Support\AtomicFileWriter;
use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * The skill drafts the dream pass proposes (roadmap 5.4-3, the opt-in
 * `memory.dreamProposeSkills` mode), kept in `~/.sugar-crush/skills-proposed/<name>/SKILL.md`
 * until the user accepts or rejects each one with `/skills`.
 *
 * WHY A SEPARATE TREE. The dream pass is an unattended, billed turn over
 * journal text that earlier conversations — and the files they read — wrote.
 * A skill is prompt text the next session treats as the operator's own, so a
 * dream that could write one would be a path from a poisoned summary to a
 * standing instruction. The dream therefore never edits a live skill: a
 * proposal lands here, a directory no skill loader walks
 * ({@see SkillLoader}'s user tier is `~/.sugar-crush/skills`, and a draft is
 * never written under any live skills directory), and only {@see accept()} —
 * reached from the `/skills accept` slash command, a user action, and from no
 * tool — copies one into the user tier.
 *
 * WHAT {@see propose()} ENFORCES, since the fields are model output: the name
 * is sanitised to a lower-case dash-separated slug of at most
 * {@see MAX_NAME_LENGTH} characters, so it can never name a path outside this
 * tree; secrets are blanked through {@see SecretRedactor} in every field; the
 * description is one line of at most {@see MAX_DESCRIPTION_CHARS} characters
 * and the body at most {@see MAX_BODY_BYTES} bytes, refused rather than cut;
 * the rendered file must parse back through {@see Skill::parse()}; an existing
 * draft is never overwritten (the user may be editing it); and directories are
 * created 0700, the file 0600, with a symlinked tree or draft refused.
 *
 * HOME MUST BE THIS USER'S. The tree is rooted at {@see HomeDirectory::owned()},
 * not {@see HomeDirectory::path()}: a world-writable or foreign `HOME` gives no
 * store at all rather than drafts in a directory another user can seed.
 */
final class ProposedSkills
{
    /** Where the drafts live, under the home directory. */
    public const SUBDIR = '.sugar-crush/skills-proposed';

    /** The user's live skills tree, under the home directory — where an accepted draft goes. */
    public const LIVE_SUBDIR = '.sugar-crush/skills';

    /** The most proposals one dream pass may make. */
    public const MAX_PER_PASS = 3;

    /** The longest draft name. */
    public const MAX_NAME_LENGTH = 64;

    /** The longest description, in characters. */
    public const MAX_DESCRIPTION_CHARS = 300;

    /** The largest body, in bytes. */
    public const MAX_BODY_BYTES = 16_384;

    /** A draft name as {@see sanitizeName()} leaves it — the only spelling `/skills` accepts. */
    private const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    private function __construct(
        private readonly ?string $home,
        private readonly SecretRedactor $redactor,
    ) {
    }

    /**
     * The store under $home, or under {@see HomeDirectory::owned()} when null;
     * with no owned home it has no tree and every write refuses.
     */
    public static function new(?string $home = null): self
    {
        $home ??= HomeDirectory::owned();

        return new self($home === null ? null : (rtrim($home, '/') ?: '/'), SecretRedactor::new());
    }

    /** `~/.sugar-crush/skills-proposed`, or null with no owned home. */
    public function root(): ?string
    {
        return $this->home === null ? null : $this->home . '/' . self::SUBDIR;
    }

    /** `~/.sugar-crush/skills`, or null with no owned home. */
    public function liveRoot(): ?string
    {
        return $this->home === null ? null : $this->home . '/' . self::LIVE_SUBDIR;
    }

    /**
     * $raw as a draft name: lower-cased, every run of other characters one
     * dash, trimmed of dashes, at most {@see MAX_NAME_LENGTH} — or null when
     * nothing is left. `../../skills/x` becomes `skills-x`: a name can never
     * be a path.
     */
    public static function sanitizeName(string $raw): ?string
    {
        $name = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($raw)) ?? '', '-');
        $name = rtrim(substr($name, 0, self::MAX_NAME_LENGTH), '-');

        return $name === '' ? null : $name;
    }

    /** Whether $name is already a sanitised draft name — what `/skills accept|reject` insist on. */
    public static function isDraftName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Write $proposal as a draft and answer its name.
     *
     * @throws \RuntimeException when there is no owned home, the tree or the
     *         draft is a symlink, a draft of that name is waiting already, or
     *         the write fails
     * @throws \InvalidArgumentException when the name sanitises to nothing,
     *         the description or body is empty or over its cap, or the
     *         rendered file does not parse as a skill
     */
    public function propose(SkillProposal $proposal): string
    {
        $root = $this->requireRoot();

        $name = self::sanitizeName($this->redactor->redact($proposal->name));
        if ($name === null) {
            throw new \InvalidArgumentException('the proposed skill has no usable name');
        }

        $description = trim(preg_replace('/\s+/u', ' ', $this->redactor->redact(self::scrub($proposal->description))) ?? '');
        if ($description === '') {
            throw new \InvalidArgumentException("the proposed skill \"{$name}\" has no description");
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION_CHARS) {
            throw new \InvalidArgumentException(sprintf(
                'the proposed skill "%s" has a description over %d characters',
                $name,
                self::MAX_DESCRIPTION_CHARS,
            ));
        }

        $body = trim($this->redactor->redact(self::scrub($proposal->body)));
        if ($body === '') {
            throw new \InvalidArgumentException("the proposed skill \"{$name}\" has no body");
        }
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'the proposed skill "%s" has a body over %s bytes',
                $name,
                number_format(self::MAX_BODY_BYTES),
            ));
        }

        $content = self::render($name, $description, $body);
        // Parsed back by the loader that will one day read it, so a draft the
        // user is asked to review is always one `/skills accept` can promote.
        Skill::parse($content, $name);

        $this->ensureTree($root);
        $dir = $root . '/' . $name;
        clearstatcache(true, $dir);
        if (is_link($dir) || file_exists($dir)) {
            throw new \RuntimeException("a draft named \"{$name}\" is already waiting for review");
        }
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException("could not create the draft directory for \"{$name}\"");
        }
        @chmod($dir, 0700);

        AtomicFileWriter::write($dir . '/SKILL.md', $content, 0600);

        return $name;
    }

    /**
     * Every waiting draft, sorted by name: its name, description (or why it
     * does not parse), size and modification time.
     *
     * @return list<array{name: string, description: ?string, error: ?string, bytes: int, modified: int, path: string}>
     */
    public function drafts(): array
    {
        $root = $this->root();
        if ($root === null || is_link($root) || !is_dir($root)) {
            return [];
        }

        $names = @scandir($root) ?: [];
        sort($names);
        $drafts = [];
        foreach ($names as $name) {
            if (!self::isDraftName($name)) {
                continue;
            }
            $file = $root . '/' . $name . '/SKILL.md';
            if (is_link($root . '/' . $name) || is_link($file) || !is_file($file)) {
                continue;
            }

            $description = null;
            $error = null;
            try {
                $description = Skill::fromFile($file)->description;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $drafts[] = [
                'name' => $name,
                'description' => $description,
                'error' => $error,
                'bytes' => (int) @filesize($file),
                'modified' => (int) @filemtime($file),
                'path' => $file,
            ];
        }

        return $drafts;
    }

    /**
     * Promote the draft $name into `~/.sugar-crush/skills/<name>/SKILL.md`
     * and delete the draft; answer the live file's path. A USER ACTION ONLY:
     * the `/skills accept` command is its one caller.
     *
     * The draft is validated by the skill loader first — its frontmatter by
     * {@see SkillLoader::loadSkillManifest()}, the whole file by
     * {@see Skill::fromFile()} — and the promotion is refused when a live skill
     * of that name exists in any native tier ({@see SkillLoader::loadAllManifests()})
     * or its directory is already in the user tier, unless $replace. A replace
     * rewrites only that directory's `SKILL.md`; anything else in it stays.
     *
     * @throws \InvalidArgumentException when $name is not a draft name or the draft does not validate
     * @throws \RuntimeException when there is no such draft, a live skill clashes, or the write fails
     */
    public function accept(string $name, bool $replace = false, string $projectRoot = '.'): string
    {
        [$dir, $file] = $this->draft($name);

        $loader = new SkillLoader(false);
        $loader->loadSkillManifest($dir);
        Skill::fromFile($file);
        $content = SkillFileReader::read($file, 'proposed SKILL.md');

        $live = $this->liveRoot() . '/' . $name;
        clearstatcache(true, $live);
        $existing = $loader->loadAllManifests($projectRoot)[$name] ?? null;
        $occupied = is_link($live) || file_exists($live);
        if (($existing !== null || $occupied) && !$replace) {
            $where = $existing !== null
                ? sprintf('a %s skill at %s', $existing['origin']->value, $existing['sourcePath'])
                : $live;
            throw new \RuntimeException(sprintf(
                'a live skill named "%s" exists already (%s); `/skills accept %s --replace` replaces it',
                $name,
                $where,
                $name,
            ));
        }
        if ($occupied && (is_link($live) || !is_dir($live))) {
            throw new \RuntimeException("{$live} is not a plain directory; not replaced");
        }

        AtomicFileWriter::write($live . '/SKILL.md', $content, 0600);
        $this->remove($dir);

        return $live . '/SKILL.md';
    }

    /**
     * Delete the draft $name.
     *
     * @throws \InvalidArgumentException when $name is not a draft name
     * @throws \RuntimeException when there is no such draft or it cannot be removed
     */
    public function reject(string $name): void
    {
        [$dir] = $this->draft($name);
        $this->remove($dir);
    }

    /**
     * The draft directory and file for $name, checked to be a real draft.
     *
     * @return array{0: string, 1: string}
     */
    private function draft(string $name): array
    {
        $root = $this->requireRoot();
        if (!self::isDraftName($name)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a draft name (lower-case letters, digits and dashes); `/skills proposed` lists them',
                $name,
            ));
        }

        $dir = $root . '/' . $name;
        $file = $dir . '/SKILL.md';
        clearstatcache();
        if (is_link($root) || is_link($dir) || !is_dir($dir) || is_link($file) || !is_file($file)) {
            throw new \RuntimeException("no draft named \"{$name}\"; `/skills proposed` lists them");
        }

        return [$dir, $file];
    }

    private function requireRoot(): string
    {
        $root = $this->root();
        if ($root === null) {
            throw new \RuntimeException('there is no home directory this user owns to keep skill drafts in');
        }

        return $root;
    }

    /** Create the drafts tree owner-only, refusing one that is a link or sits inside the live tree. */
    private function ensureTree(string $root): void
    {
        clearstatcache(true, $root);
        if (is_link($root)) {
            throw new \RuntimeException("{$root} is a symlink; no draft is written through it");
        }
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new \RuntimeException("could not create {$root}");
        }
        @chmod($root, 0700);

        // ContainedPath::within() answers false when the live tree does not
        // exist yet, which is the case with nothing to be inside.
        if (realpath($root) === false || ContainedPath::within($root, (string) $this->liveRoot())) {
            throw new \RuntimeException("{$root} resolves inside the live skills directory; no draft is written there");
        }
    }

    /** Remove a draft directory without following any link inside it. */
    private function remove(string $dir): void
    {
        $entries = @scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_link($path) && is_dir($path)) {
                $this->remove($path);
                continue;
            }
            @unlink($path);
        }

        if (!@rmdir($dir)) {
            throw new \RuntimeException("could not remove {$dir}");
        }
    }

    private static function render(string $name, string $description, string $body): string
    {
        // A JSON string is a valid YAML double-quoted scalar, so whatever the
        // description holds (a colon, a quote, a `#`) it stays one value.
        return "---\nname: {$name}\ndescription: "
            . json_encode($description, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            . "\n---\n\n" . $body . "\n";
    }

    private static function scrub(string $text): string
    {
        return mb_scrub(str_replace("\0", '', $text), 'UTF-8');
    }
}
