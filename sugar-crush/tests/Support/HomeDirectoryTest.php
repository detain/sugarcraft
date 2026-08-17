<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * ONE resolution of `~` for the whole package.
 *
 * The migration this class finishes was worse HALF-DONE than not done at all:
 * while every consumer read `$_SERVER['HOME'] ?? '/root'` they were
 * consistently wrong together, but once the skill trees moved to `getenv()` a
 * single `putenv('HOME', …)` — the exact call the move was motivated by —
 * pointed `~/.claude/skills` and `~/.claude/agents` at two different homes,
 * with two different fallbacks (`/tmp` and `/root`) behind them.
 */
final class HomeDirectoryTest extends TestCase
{
    use HomeSandboxTrait;

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();

        parent::tearDown();
    }

    public function testTheEnvironmentVariableIsWhatDecides(): void
    {
        $dir = sys_get_temp_dir() . '/home_directory_env_' . uniqid('', true);
        $this->useHomeSandbox($dir);
        $_SERVER['HOME'] = '/definitely/not/this/one';

        $this->assertSame($dir, HomeDirectory::path());
        $this->assertSame($dir, HomeDirectory::resolved());

        @rmdir($dir);
    }

    /**
     * EVERY consumer goes through this one resolution. Listed by name rather
     * than asserted in aggregate because the failure being prevented is one
     * file drifting back, and a grep-shaped test names the file that did.
     *
     * @return array<string, array{0: string}>
     */
    public static function migratedFiles(): array
    {
        $files = [
            'src/Agents/ForeignAgentPresetRegistry.php',
            'src/Agents/Team.php',
            'src/Agents/TeamManager.php',
            'src/Agents/Teammate.php',
            'src/Agents/WorktreeManager.php',
            'src/Commands/CommandLoader.php',
            'src/Memory/ForeignMemoryImporter.php',
            'src/Skills/ForeignSkillDiscovery.php',
            'src/Skills/SkillDiscovery.php',
            'src/Skills/SkillLoader.php',
            'src/Workflows/WorkflowEngine.php',
            'src/Workflows/WorkflowRegistry.php',
            'src/Cli/Bootstrap.php',
        ];

        return array_combine($files, array_map(static fn(string $f): array => [$f], $files));
    }

    /**
     * @dataProvider migratedFiles
     */
    public function testNoConsumerReadsTheServerSuperglobalDirectly(string $relative): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $source = (string) file_get_contents($path);

        $this->assertStringNotContainsString(
            '$_SERVER[\'HOME\']',
            $source,
            $relative . ' must resolve ~ through HomeDirectory, not its own copy of the rule',
        );
    }

    /**
     * The passwd database is asked before any stand-in: the environment not
     * SAYING where home is does not mean nobody knows, and that is the case
     * cron, systemd, `env -i` and `sudo` without `-E` all produce.
     */
    public function testAnUnsetHomeFallsBackToThePasswdEntryNotToTheTempDirectory(): void
    {
        if (!\function_exists('posix_getpwuid') || !\function_exists('posix_geteuid')) {
            $this->markTestSkipped('ext-posix is not available');
        }

        $expected = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        if (!is_string($expected) || $expected === '') {
            $this->markTestSkipped('this uid has no passwd home directory');
        }

        $this->useHomeSandbox(sys_get_temp_dir() . '/home_directory_unset_' . uniqid('', true));
        putenv('HOME');
        putenv('USERPROFILE');

        $this->assertSame($expected, HomeDirectory::resolved());
    }

    // ─── owned(): the ownership claim, made real ────────────────────

    /**
     * THE MEASUREMENT THIS METHOD EXISTS FOR. `Bootstrap::trustedConfigDirPath()`
     * was documented — and cited by `Bootstrap::agentPresets()` — as refusing "a
     * home this process cannot establish ownership of" while performing no
     * `stat` anywhere in the package. Measured on this host before {@see
     * HomeDirectory::owned()} existed, with `HOME` pointed at a mode-1777
     * directory: `trustedConfigDirPath()` returned `<that dir>/.sugar-crush` and
     * the user tier read a preset out of it.
     *
     * The sticky bit is why a mode check has to be `& 0002` rather than "is it
     * /tmp": `/tmp` is 1777, and sticky stops other users DELETING entries, not
     * CREATING them, so `<world-writable>/.sugar-crush` is pre-creatable by any
     * local account.
     */
    public function testAWorldWritableHomeResolvesButIsNotOwned(): void
    {
        $dir = $this->useHomeSandbox(sys_get_temp_dir() . '/home_directory_ww_' . uniqid('', true));
        chmod($dir, 0o1777);

        $this->assertSame($dir, HomeDirectory::resolved(), 'it still RESOLVES — that is the weaker question');
        $this->assertNull(HomeDirectory::owned());

        chmod($dir, 0o700);
        @rmdir($dir);
    }

    /**
     * The spelling is preserved rather than resolved: every caller concatenates
     * onto this, and returning a `realpath()` would silently rewrite `/tmp/...`
     * to `/private/tmp/...` on macOS in every refusal key and message.
     */
    public function testAnOwnedHomeComesBackAsSpelled(): void
    {
        $dir = $this->useHomeSandbox(sys_get_temp_dir() . '/home_directory_owned_' . uniqid('', true));
        chmod($dir, 0o700);

        $this->assertSame($dir, HomeDirectory::owned());

        @rmdir($dir);
    }

    /**
     * THE CLAUSE THE OWNERSHIP WORK WAS TITLED FOR, and it had ZERO coverage.
     * Deleting the last two lines of {@see HomeDirectory::owned()} — replacing
     * `return $owner === false || $owner !== posix_geteuid() ? null : $home;`
     * with `return $home;` — left the FULL suite byte-identical at
     * `6603 / 68775`, while the doc-block called it "the clause that kills the
     * other direction". Every neighbouring test drove world-writable,
     * sticky+world-writable, group-writable, nonexistent, is-a-file and
     * as-spelled; none drove "owned by another uid", which is the only thing
     * this clause does.
     *
     * MEASURED in one line: `HOME=/usr` (drwxr-xr-x root root) gave
     * `owned() = NULL` intact and `owned() = "/usr"` with the clause deleted,
     * at which point `Bootstrap::agentPresets()` proceeds instead of throwing.
     *
     * The fixture is a REAL system directory rather than a fabricated one,
     * because creating a directory owned by another uid needs privileges this
     * suite does not have. It is skipped rather than faked when no such
     * directory exists — running as root, or a host where these are not
     * root-owned.
     */
    public function testAHomeOwnedByAnotherAccountIsNamedButNotOwned(): void
    {
        if (!\function_exists('posix_geteuid')) {
            $this->markTestSkipped('the ownership clause is POSIX-only by construction');
        }

        $foreign = null;
        foreach (['/usr', '/etc', '/bin', '/opt', '/var'] as $candidate) {
            $perms = @fileperms($candidate);
            $owner = @fileowner($candidate);

            // Not world-writable, so a NULL answer can only come from the
            // ownership clause and not from the mode clause above it.
            if (is_dir($candidate) && $perms !== false && ($perms & 0o002) === 0
                && $owner !== false && $owner !== posix_geteuid()
            ) {
                $foreign = $candidate;

                break;
            }
        }

        if ($foreign === null) {
            $this->markTestSkipped('no non-world-writable directory owned by another uid on this host');
        }

        $this->useHomeSandbox($foreign);

        $this->assertSame($foreign, HomeDirectory::resolved(), 'it still RESOLVES — that is the weaker question');
        $this->assertNull(HomeDirectory::owned(), 'a home owned by another account is not this user\'s');
    }

    /**
     * The same clause reached through the caller it exists for: a launch whose
     * `$HOME` is another account's directory must REFUSE rather than read that
     * account's permission policy and hook chain.
     */
    public function testABootstrapLaunchRefusesAHomeOwnedByAnotherAccount(): void
    {
        if (!\function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('needs a non-root euid and ext-posix');
        }

        $perms = @fileperms('/usr');
        if (!is_dir('/usr') || $perms === false || ($perms & 0o002) !== 0 || @fileowner('/usr') === posix_geteuid()) {
            $this->markTestSkipped('/usr is not a foreign-owned, non-world-writable directory on this host');
        }

        $this->useHomeSandbox('/usr');

        $this->expectException(\SugarCraft\Crush\Cli\PermissionConfigException::class);
        \SugarCraft\Crush\Cli\Bootstrap::agentPresets(sys_get_temp_dir());
    }

    /**
     * A RELATIVE `$HOME` is refused, and this is the one case where "gate on
     * `realpath()`, return as spelled" genuinely diverges: `realpath('.')` gates
     * a real directory while `'.'` names a different one after any `chdir()`.
     *
     * MEASURED before the refusal, cwd inside a checkout and `HOME=.`:
     * `owned() = "."`, `trustedConfigDirPath() = "./.sugar-crush"`, and
     * `agentPresets(<some other project>)` gave
     * `presets=["relhome"] mode=bypass-permissions refusals=[]` — the checkout's
     * own agents directory read as the privileged USER tier.
     *
     * @return array<string, array{0: string}>
     */
    public static function relativeHomeSpellings(): array
    {
        return ['the cwd itself' => ['.'], 'a child of the cwd' => ['./sub'], 'a bare name' => ['sub']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('relativeHomeSpellings')]
    public function testARelativeHomeIsNamedButNotOwned(string $spelling): void
    {
        $dir = sys_get_temp_dir() . '/home_directory_rel_' . uniqid('', true);
        mkdir($dir . '/sub', 0o700, true);

        $cwd = (string) getcwd();
        chdir($dir);

        try {
            $this->useHomeSandbox($spelling, create: false);

            $this->assertSame($spelling, HomeDirectory::resolved(), 'it still RESOLVES');
            $this->assertNull(HomeDirectory::owned());
        } finally {
            chdir($cwd);
            @rmdir($dir . '/sub');
            @rmdir($dir);
        }
    }

    /**
     * A trailing separator is stripped rather than carried into every derived
     * path: `HOME=/path/` produced `/path//.sugar-crush` in each one AND in
     * every refusal key, so one directory was keyed two ways.
     */
    public function testATrailingSeparatorIsStrippedFromAnOwnedHome(): void
    {
        $dir = $this->useHomeSandbox(sys_get_temp_dir() . '/home_directory_slash_' . uniqid('', true));
        chmod($dir, 0o700);

        $this->useHomeSandbox($dir . '/', create: false);

        $this->assertSame($dir . '/', HomeDirectory::resolved(), 'resolved() is still literally the environment');
        $this->assertSame($dir, HomeDirectory::owned());

        @rmdir($dir);
    }

    /**
     * GROUP-WRITABLE IS ACCEPTED, asserted rather than left to the doc-block:
     * `umask 002` layouts give real home directories mode 0775, and refusing
     * those would break working installations to defend against a group the
     * user already trusts. This is the check's stated residual, executable.
     */
    public function testAGroupWritableHomeIsStillOwned(): void
    {
        $dir = $this->useHomeSandbox(sys_get_temp_dir() . '/home_directory_gw_' . uniqid('', true));
        chmod($dir, 0o770);

        $this->assertSame($dir, HomeDirectory::owned());

        chmod($dir, 0o700);
        @rmdir($dir);
    }

    public function testAHomeThatDoesNotExistIsNamedButNotOwned(): void
    {
        $dir = sys_get_temp_dir() . '/home_directory_absent_' . uniqid('', true);
        $this->useHomeSandbox($dir);
        rmdir($dir);

        $this->assertSame($dir, HomeDirectory::resolved());
        $this->assertNull(HomeDirectory::owned());
    }

    /**
     * A home that is a FILE, not a directory — the shape `HOME=$(mktemp)`
     * produces — is named and not owned.
     */
    public function testAHomeThatIsAFileIsNotOwned(): void
    {
        $dir = sys_get_temp_dir() . '/home_directory_file_' . uniqid('', true);
        $this->useHomeSandbox($dir);
        rmdir($dir);
        file_put_contents($dir, '');

        $this->assertNull(HomeDirectory::owned());

        unlink($dir);
    }

    /**
     * The fallback {@see HomeDirectory::path()} documents, measured on THIS host
     * rather than argued: `php -d disable_functions=posix_geteuid,posix_getpwuid`
     * with `HOME` and `USERPROFILE` unset returned
     *
     *     resolved() = NULL
     *     path()     = /tmp   (mode 41777, drwxrwxrwt)
     *     owned()    = NULL
     *
     * so the stand-in a user-tier reader would get IS world-writable, and
     * `owned()` is the answer that refuses it. Only the last two lines are
     * asserted here: unsetting `HOME` inside a suite that already sandboxes it
     * would leave `resolved()` reading the passwd entry, which is the correct
     * behaviour and a different test ({@see
     * testAnUnsetHomeFallsBackToThePasswdEntryNotToTheTempDirectory()}).
     */
    public function testTheDocumentedTempDirectoryStandInIsWorldWritable(): void
    {
        $temp = sys_get_temp_dir();
        $perms = fileperms($temp);

        $this->assertNotFalse($perms);
        $this->assertNotSame(0, $perms & 0o002, 'sys_get_temp_dir() is world-writable on this host');

        $this->useHomeSandbox($temp);
        $this->assertSame($temp, HomeDirectory::path());
        $this->assertNull(HomeDirectory::owned(), 'and owned() is what refuses it');
    }
}
