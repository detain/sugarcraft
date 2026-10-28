<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\ForeignCommandDiscovery;
use SugarCraft\Code\Skills\SkillSource;

/**
 * INT-3: foreign command trees are IMPORTED, BADGED and RANKED LAST.
 *
 * The three claims this file exists to keep true, each measured rather than
 * eyeballed because the interesting failures are silent ones:
 *
 *  - strict-bottom (ruling R7): a foreign row loses a built-in's name with NO
 *    refusal and NO override row — the loss is the design, and the loudest
 *    bug this feature could ship is a foreign `compact.md` that DOES win, or
 *    one that wins attention it never earned;
 *  - one containment implementation: the escape and refusal shapes are the
 *    native ones because the walk goes through {@see CommandLoader::loadFromDirectory()}
 *    on the shared instance — the collectors Bootstrap drains are the proof;
 *  - the two axes stay independent: `tier` still answers the shell question
 *    (project rows arrive tier-stamped from foreign trees too), `source` only
 *    ever answers the display question.
 */
final class ForeignCommandDiscoveryTest extends TestCase
{
    private string $tmp;
    private string $repo;
    private string $home;

    /** @var string|false */
    private $homeBefore;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/crush-foreigncmd-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o700, true);
        $this->repo = $this->tmp . '/repo';
        mkdir($this->repo, 0o700, true);
        // The fake home is a SUBDIRECTORY of the scratch root, the way the
        // tree-level escapes are constructed in CommandLoaderTest — 'outside
        // the home' and 'inside the same parent' have to be different claims
        // for the refusal tests to refuse anything.
        $this->home = $this->tmp . '/home';
        mkdir($this->home, 0o700, true);
        $this->homeBefore = $_SERVER['HOME'] ?? false;
        $_SERVER['HOME'] = $this->home;
        putenv('HOME=' . $this->home);
    }

    protected function tearDown(): void
    {
        if ($this->homeBefore === false) {
            unset($_SERVER['HOME']);
            putenv('HOME');
        } else {
            $_SERVER['HOME'] = $this->homeBefore;
            putenv('HOME=' . $this->homeBefore);
        }

        $this->wipeTree($this->tmp);
    }

    private function wipeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                $this->wipeTree($path);
            }
        }

        rmdir($dir);
    }

    /** @param string|null $base directory tree to write under — default the USER home tree. */
    private function put(string $relativePath, string $content, ?string $base = null): string
    {
        $path = ($base ?? $this->home) . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    private function described(string $body): string
    {
        return "---\ndescription: an imported row\n---\n" . $body;
    }

    // ------------------------------------------------------------------
    // discovery, tagging, ordering
    // ------------------------------------------------------------------

    public function testBothConventionsAreReadAndBadgedWithTheirFormat(): void
    {
        $this->put('.claude/commands/notes.md', $this->described('claude user body'));
        $this->put('.opencode/command/review.md', $this->described('opencode user body'));

        $commands = (new ForeignCommandDiscovery())->discover($this->repo);

        self::assertSame(['notes', 'review'], array_keys($commands), 'rows arrive sorted like the native walk sorts each directory');
        self::assertSame('claude user body', $commands['notes']->template);
        self::assertSame(SkillSource::Claude, $commands['notes']->source);
        self::assertSame('[claude]', $commands['notes']->sourceBadge());
        self::assertSame('user', $commands['notes']->tier);
        self::assertSame('[user]', $commands['notes']->tierBadge());
        self::assertSame(SkillSource::Opencode, $commands['review']->source);
        self::assertSame('[opencode]', $commands['review']->sourceBadge());
    }

    public function testProjectTreeRowsCarryTheProjectTierSoTheShellGateStillSeesThem(): void
    {
        // INT-3 keeps the ISS-036 verdict INTACT rather than re-arguing it:
        // the tier is what TurnController gates `` !`…` `` on, and a cloned
        // `.claude/commands` row must arrive stamped 'project' to inherit it.
        $this->put('.claude/commands/notes.md', $this->described('project body'), $this->repo);

        $commands = (new ForeignCommandDiscovery())->discover($this->repo);

        self::assertSame('project', $commands['notes']->tier);
        self::assertSame('[project]', $commands['notes']->tierBadge());
    }

    public function testTheUserTreeBeatsTheProjectTreeAndOpencodeBeatsClaudeInsideATier(): void
    {
        // Tiers first, format tie inside a tier — the interleaving rule
        // ForeignSkillDiscovery draws for skills, applied to commands.
        $this->put('.claude/commands/notes.md', $this->described('claude project'), $this->repo);
        $this->put('.opencode/command/notes.md', $this->described('opencode project'), $this->repo);
        $this->put('.claude/commands/notes.md', $this->described('claude user'));
        $this->put('.opencode/command/notes.md', $this->described('opencode user'));

        $commands = (new ForeignCommandDiscovery())->discover($this->repo);

        self::assertSame('opencode user', $commands['notes']->template, 'user tier AND opencode format both outrank their twins');
        self::assertSame(SkillSource::Opencode, $commands['notes']->source);
    }

    public function testTheXdgTreeBeatsTheLegacyTreeInsideOpencode(): void
    {
        $this->put('.opencode/commands/deploy.md', $this->described('legacy tree'));
        $this->put('.config/opencode/commands/deploy.md', $this->described('xdg tree'));

        $commands = (new ForeignCommandDiscovery())->discover($this->repo);

        self::assertSame('xdg tree', $commands['deploy']->template);
    }

    // ------------------------------------------------------------------
    // strict bottom (ruling R7)
    // ------------------------------------------------------------------

    public function testForeignRowsCannotShadowABuiltInAndLoseSilently(): void
    {
        // `/compact` is replaceable by a NATIVE user file with a reported
        // override; a FOREIGN one of the same name must not replace it at all
        // AND must not report anything — the silent loss is the ruling, and
        // this is the one place silence is the asserted behaviour.
        // C-AGT-6.a (R7): `/commit` is the live case the plan's risks block
        // names — a cloned project carrying its own `.claude/commands/
        // commit.md` must still answer with the built-in, because strict-bottom
        // puts the import below every built-in, not merely above the tiers an
        // override may cross.
        $loader = new CommandLoader();
        $this->put('.claude/commands/compact.md', $this->described('imported compact'));
        $this->put('.claude/commands/exit.md', $this->described('imported exit'));
        $this->put('.claude/commands/commit.md', $this->described('imported commit'));

        $merged = $loader->loadAll($this->repo);

        self::assertFalse($merged['compact']->isFileBased(), 'the built-in row must still answer /compact');
        self::assertFalse($merged['exit']->isFileBased(), 'and the control-plane reservation is not even reached — the foreign row never won');
        self::assertFalse($merged['commit']->isFileBased(), 'the C-AGT-6.a built-in must still answer /commit');
        self::assertSame([], $loader->overrides(), 'a row that never won overrides nothing');
        self::assertSame([], $loader->refusedCommands(), 'and loses its name in silence, by ruling');
    }

    public function testAForeignProjectRowNeverReachesTheTrustRefusalEvenUntrusted(): void
    {
        // A NATIVE project shadow of /compact on an untrusted root is refused
        // loudly (ISS-036). A foreign one is refused NOTHING because it never
        // won — strict-bottom puts the import below the question the grant
        // answers, which is the whole reason the rule is R7 and not a tier.
        $loader = new CommandLoader();
        $this->put('.claude/commands/compact.md', $this->described('cloned shadow'), $this->repo);

        $merged = $loader->loadAll($this->repo, false);

        self::assertFalse($merged['compact']->isFileBased());
        self::assertSame([], $loader->refusedCommands());
    }

    public function testTheWinnerStillNamesItsOwnSourceFile(): void
    {
        // The {@see CommandLoader::$commandSources} invariant the refusal and
        // override loops lean on: the path stored for a name is the file that
        // WON it. The native tier beats the foreign one AND is walked after,
        // so the report for a real override must name the native file even
        // though a foreign claimant of the same name was read first.
        $loader = new CommandLoader();
        $this->put('.claude/commands/compact.md', $this->described('foreign claimant'));
        $this->put('.candy-code/commands/compact.md', $this->described('native winner'));

        $merged = $loader->loadAll($this->repo);

        self::assertTrue($merged['compact']->isFileBased());
        self::assertNull($merged['compact']->source, 'the native convention carries no foreign badge');
        $row = (string) ($loader->overrides()['compact'] ?? '');
        self::assertStringContainsString('.candy-code', $row, 'the override report must name the winner');
        self::assertStringNotContainsString('.claude', $row, 'or the report is naming a file that decided nothing');
    }

    // ------------------------------------------------------------------
    // opencode's `template:` body
    // ------------------------------------------------------------------

    public function testAnEmptyOpencodeBodyFoldsFromTheTemplateKey(): void
    {
        // opencode keeps the PROMPT in frontmatter and leaves the body for
        // docs; without the fold every real opencode command file would die
        // on the empty-body rejection the native format rightly enforces.
        $this->put('.opencode/command/typed.md', "---\ndescription: an imported row\ntemplate: typed body\n---\n");

        $commands = (new ForeignCommandDiscovery())->discover($this->repo);

        self::assertSame('typed body', $commands['typed']->template);
        self::assertTrue($commands['typed']->isFileBased());
    }

    public function testANonEmptyBodyWinsOverTheTemplateKey(): void
    {
        $this->put('.opencode/command/typed.md', "---\ndescription: an imported row\ntemplate: frontmatter body\n---\nbody body\n");

        $commands = (new ForeignCommandDiscovery())->discover($this->repo);

        self::assertSame('body body', $commands['typed']->template);
    }

    public function testTheTemplateKeyIsNotConsultedForTheClaudeConvention(): void
    {
        // A stray `template:` in a Claude file does not resurrect an empty
        // body — the fold answers opencode's contract, and the empty native
        // or Claude file stays the author error it has always been.
        $loader = new CommandLoader();
        $this->put('.claude/commands/typed.md', "---\ndescription: an imported row\ntemplate: typed body\n---\n");

        $commands = (new ForeignCommandDiscovery($loader))->discover($this->repo);

        self::assertArrayNotHasKey('typed', $commands);
        self::assertNotEmpty($loader->skippedFiles(), 'the skip is recorded where every other loader skip is recorded');
    }

    public function testAWronglyTypedTemplateIsRejectedRatherThanCoerced(): void
    {
        // A YAML list where a prompt string belongs is the same authoring
        // mistake `model:` would be — stringField() throws, the file skips.
        $this->put('.opencode/command/listy.md', "---\ndescription: an imported row\ntemplate: [one, two]\n---\n");

        $commands = (new ForeignCommandDiscovery())->discover($this->repo);

        self::assertArrayNotHasKey('listy', $commands);
    }

    // ------------------------------------------------------------------
    // containment, on the shared instance
    // ------------------------------------------------------------------

    public function testACommandsDirectorySymlinkedOutOfTheHomeTreeIsRefusedOnTheSharedCollector(): void
    {
        // The measured native escape (CommandLoaderTest / loadUserCommands'
        // doc-block), re-run against a foreign tree: it must refuse through
        // the SAME loader instance Bootstrap drains, because this class is a
        // directory list, not a second containment implementation.
        $loader = new CommandLoader();
        $smuggled = $this->put('outside/smuggled.md', $this->described('smuggled body'), $this->tmp);
        mkdir($this->home . '/.claude', 0o700, true);
        symlink(\dirname($smuggled), $this->home . '/.claude/commands');

        $commands = (new ForeignCommandDiscovery($loader))->discover($this->repo);

        self::assertSame([], $commands);
        self::assertNotEmpty($loader->refusedDirectories(), 'the refusal lands on the drained instance, not a throwaway');
    }

    public function testNoForeignUserTreeWhenHomeIsNotVouchedFor(): void
    {
        // HomeDirectory::owned() refuses a world-writable home — the user
        // trees then contribute NOTHING while the project tree, anchored to
        // the checkout and not to any home, still loads.
        $wide = $this->tmp . '/wide';
        mkdir($wide, 0o777, true);
        chmod($wide, 0o777);
        $_SERVER['HOME'] = $wide;
        putenv('HOME=' . $wide);
        $this->put('.claude/commands/notes.md', $this->described('unvouched user body'), $wide);
        $this->put('.claude/commands/notes.md', $this->described('project body'), $this->repo);

        $commands = (new ForeignCommandDiscovery())->discover($this->repo);

        self::assertSame(['notes'], array_keys($commands));
        self::assertSame('project body', $commands['notes']->template);
    }

    public function testForeignRowsAreExtraRowsBesideAnUntouchedRegistryCount(): void
    {
        // The floor adds rows only under NEW names; the built-in map comes
        // out of loadAll byte-identical. One foreign row, count +1.
        $this->put('.claude/commands/onlyforeign.md', $this->described('foreign only'));

        $merged = (new CommandLoader())->loadAll($this->repo);

        self::assertCount(\count(CommandRegistry::all()) + 1, $merged);
        self::assertSame('foreign only', $merged['onlyforeign']->template);
    }
}
