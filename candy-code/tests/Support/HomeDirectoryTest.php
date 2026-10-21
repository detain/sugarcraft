<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\HomeDirectory;

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
     * directory: `trustedConfigDirPath()` returned `<that dir>/.candy-code` and
     * the user tier read a preset out of it.
     *
     * The sticky bit is why a mode check has to be `& 0002` rather than "is it
     * /tmp": `/tmp` is 1777, and sticky stops other users DELETING entries, not
     * CREATING them, so `<world-writable>/.candy-code` is pre-creatable by any
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
     * THE ROOT IS NEVER AN OWNER. Behaviorally this reads as a null under a
     * non-root test process EITHER WAY (the uid clause at the bottom of
     * `owned()` already refuses `/` for anyone but root — `fileowner('/')` is
     * 0), so the null assertion is the smoke and the SOURCE SCAN is the pin:
     * the refusal must sit ABOVE the uid comparison, because the entire point
     * is that the uid comparison is a no-op for the one caller (euid 0) whose
     * `HOME=/` matters. Neuter the guard line and exactly this test reddens.
     */
    public function testAHomeOfRootIsRefusedAboveTheUidClause(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Support/HomeDirectory.php');
        $bodyStart = strpos($source, 'public static function owned(');
        $this->assertIsInt($bodyStart);
        $body = substr($source, $bodyStart);

        $guardAt = strpos($body, "\$real === '/'");
        $uidAt = strpos($body, 'posix_geteuid()');
        $this->assertNotFalse($guardAt, 'owned() no longer refuses a resolved filesystem root');
        $this->assertIsInt($uidAt, 'the ownership clause vanished — this scan has no floor to sit above');
        $this->assertLessThan($uidAt, $guardAt, "the root refusal sits BELOW the uid comparison (guard at {$guardAt}, uid at {$uidAt}) — at euid 0 the uid comparison never refuses, so a below-guard refusal is no refusal for the hazard this guards");

        $this->assertStringContainsString('THE ROOT IS NEVER AN OWNER', $source, 'the docblock truth that replaced "/ is returned as itself"');
        $this->assertStringNotContainsString('is returned as itself', $source);

        $home = getenv('HOME');

        try {
            putenv('HOME=/');
            $this->assertNull(HomeDirectory::owned(), 'HOME=/ must answer null for this (non-root) process too');
        } finally {
            if ($home === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $home);
            }
        }
    }

    /**
     * Same claim from a CLEAN process: a `php -r` child with `HOME=/` in its
     * environment (never `putenv`-ing over a live sandbox) must print NULL for
     * `owned()`. The child only calls a predicate — it creates nothing, and a
     * regression that made `owned()` answer `/` would still only be ASSERTED
     * against here, never written through, because every consumer concatenates
     * `.candy-code` onto the answer and this probe invokes none of them.
     */
    public function testAChildProcessWithRootHomeSeesNoOwner(): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'putenv("HOME=/");'
            . 'echo "OWNED=" . var_export(\\SugarCraft\\Code\\Support\\HomeDirectory::owned(), true);';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 2),
            ['PATH' => '/usr/bin:/bin', 'TMPDIR' => sys_get_temp_dir()],
        );

        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, "child probe failed: {$stderr}");
        $this->assertSame('OWNED=NULL', $stdout, 'a child whose HOME is the filesystem root must not be vouched for');
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

        $this->expectException(\SugarCraft\Code\Cli\PermissionConfigException::class);
        \SugarCraft\Code\Cli\Bootstrap::agentPresets(sys_get_temp_dir());
    }

    /**
     * A RELATIVE `$HOME` is refused, and this is the one case where "gate on
     * `realpath()`, return as spelled" genuinely diverges: `realpath('.')` gates
     * a real directory while `'.'` names a different one after any `chdir()`.
     *
     * MEASURED before the refusal, cwd inside a checkout and `HOME=.`:
     * `owned() = "."`, `trustedConfigDirPath() = "./.candy-code"`, and
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
     * path: `HOME=/path/` produced `/path//.candy-code` in each one AND in
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

    /**
     * v1:m-1, the FALSE half in-process: a launch that establishes a home
     * must not carry the stand-in record at all.
     */
    public function testAHomeThatResolvesLeavesTheStandInRecordQuiet(): void
    {
        HomeDirectory::resetStandInUsedForTesting();
        $dir = $this->useHomeSandbox(sys_get_temp_dir() . '/home_directory_record_' . uniqid('', true));

        $this->assertSame($dir, HomeDirectory::path());
        $this->assertFalse(HomeDirectory::standInUsed(), 'path() answered from the environment, not the stand-in');

        @rmdir($dir);
    }

    /**
     * v1:m-1, the TRUE half from a CLEAN process: no `HOME`, no
     * `USERPROFILE`, and the passwd probe disabled — exactly the shape
     * `resolved()` documents as answering null. The stand-in stays the
     * return value; what was missing was that the process KNEW it had
     * taken it (the launch row this flag feeds is pinned in
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapHomeStandInNoticeTest}).
     */
    public function testAChildWithNoWayToEstablishAHomeRecordsTheStandIn(): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $standIn = sys_get_temp_dir() . '/home_directory_stand_' . uniqid('', true);
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'echo "PATH=" . \\SugarCraft\\Code\\Support\\HomeDirectory::path()'
            . ' . " STANDBY=" . var_export(\\SugarCraft\\Code\\Support\\HomeDirectory::standInUsed(), true);';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=posix_geteuid,posix_getpwuid', '-r', $code],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 2),
            ['PATH' => '/usr/bin:/bin', 'TMPDIR' => $standIn],
        );

        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, "child probe failed: {$stderr}");
        $this->assertSame(
            'PATH=' . $standIn . ' STANDBY=true',
            $stdout,
            'a child that falls back to the temp-directory stand-in must say so in its own process record',
        );
    }

    /**
     * v1:m-1 polarity pair: the SAME disabled-posix child, but with `HOME`
     * in its environment, resolves and must not set the flag — the record
     * tracks the stand-in, not the absence of passwd.
     */
    public function testAChildWithAHomeInTheEnvironmentDoesNotRecordTheStandIn(): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $home = sys_get_temp_dir() . '/home_directory_envonly_' . uniqid('', true);
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'echo "PATH=" . \\SugarCraft\\Code\\Support\\HomeDirectory::path()'
            . ' . " STANDBY=" . var_export(\\SugarCraft\\Code\\Support\\HomeDirectory::standInUsed(), true);';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=posix_geteuid,posix_getpwuid', '-r', $code],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 2),
            ['PATH' => '/usr/bin:/bin', 'TMPDIR' => sys_get_temp_dir(), 'HOME' => $home],
        );

        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, "child probe failed: {$stderr}");
        $this->assertSame('PATH=' . $home . ' STANDBY=false', $stdout);
    }
}
