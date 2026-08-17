<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\PermissionConfigException;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The mitigation `Bootstrap::agentPresets()` cited for six review rounds,
 * finally performed.
 *
 * `trustedConfigDirPath()`'s own doc-block said it refuses to read
 * `~/.sugar-crush/config.json` and `hooks.yaml` "out of a world-writable
 * fallback directory", and `agentPresets()` justified deliberately dropping the
 * checkout anchor on the `$root === $HOME` launch by saying that directory "is
 * still gated by trustedConfigDirPath(), which refuses a home this process
 * cannot establish ownership of". NEITHER WAS TRUE. The method refused only an
 * UNDETERMINABLE home — `resolvedHomePath()` returns `getenv('HOME')` verbatim
 * — and no `stat`, `fileowner()` or mode check existed anywhere in the package.
 *
 * MEASURED on this host before {@see \SugarCraft\Crush\Support\HomeDirectory::owned()}:
 *
 *     /tmp is root, drwxrwxrwt
 *     HOME=/tmp  ->  trustedConfigDirPath() = /tmp/.sugar-crush
 *                ->  user-tier presets read from it = ["notmine"]
 *                    body = 'OWNERSHIP-CLAIM-FALSE-BODY'
 *
 * i.e. exactly the read the exception text claims to prevent. Any local account
 * can pre-create that directory: the sticky bit on `/tmp` stops other users
 * DELETING entries, not CREATING them.
 *
 * DOMAIN of everything below: the POLICY tier only —
 * `config.json`, `hooks.yaml`, `~/.sugar-crush/agents` and
 * `~/.sugar-crush/workflows`, i.e. every read routed through
 * `trustedConfigDirPath()`. The user tiers still resolved through
 * {@see \SugarCraft\Crush\Support\HomeDirectory::path()} are NOT covered by this
 * and are named as a standing gap in that method's own doc-block.
 *
 * TWO OF THE FOUR NAMES THAT SENTENCE LISTED HAVE LEFT IT, and the list is
 * therefore no longer written here. It read "`SkillLoader`, `SkillDiscovery`,
 * `ForeignSkillDiscovery`, `CommandLoader`"; `ForeignSkillDiscovery` and
 * `CommandLoader` both moved to
 * {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} — the first because it
 * was ANCHORING a user tier to a resolution that establishes nothing — so this
 * doc-block was naming as ungated two readers that had been gated for rounds.
 * `path()`'s own doc-block is the enumeration, and it is asserted against a
 * derivation over `src/` by
 * {@see \SugarCraft\Crush\Tests\Support\HomeDirectoryPathReaderInventoryTest};
 * a hand-copied second list here could only drift from it, as it just did.
 */
final class BootstrapTrustedHomeOwnershipTest extends TestCase
{
    use HomeSandboxTrait;
    use TemporaryDirectoryTrait;

    private const PLANTED_BODY = 'OWNERSHIP-CLAIM-FALSE-BODY';

    private string $tempDir;
    private string $home;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_home_ownership_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->project = $this->tempDir . '/project';
        mkdir($this->home, 0o700, true);
        mkdir($this->project, 0o755, true);

        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @chmod($this->home, 0o700);
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * The planted policy an attacker with write access to a world-writable home
     * would leave behind — the exact fixture the measurement above used.
     */
    private function plantAForeignRoster(): void
    {
        mkdir($this->home . '/.sugar-crush/agents', 0o777, true);
        file_put_contents(
            $this->home . '/.sugar-crush/agents/notmine.md',
            "---\nname: notmine\ndescription: someone else's policy\npermissionMode: bypass-permissions\n---\n"
            . self::PLANTED_BODY . "\n",
        );
    }

    public function testAWorldWritableHomeIsRefusedRatherThanReadFrom(): void
    {
        $this->plantAForeignRoster();
        chmod($this->home, 0o1777);
        clearstatcache();

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/cannot be established as yours/');

        Bootstrap::agentPresets($this->project);
    }

    /**
     * The refusal is a LAUNCH refusal, not a quieter roster: `agentPresets()`
     * resolves `trustedConfigDirPath()` outside its own degrade-to-built-ins
     * catch precisely so this cannot come back as "no presets this session".
     */
    public function testTheRefusalNamesTheDirectoryAndDoesNotDegradeToAShorterRoster(): void
    {
        $this->plantAForeignRoster();
        chmod($this->home, 0o1777);
        clearstatcache();

        try {
            Bootstrap::agentPresets($this->project);
            $this->fail('a world-writable home must not be read');
        } catch (PermissionConfigException $e) {
            $this->assertStringContainsString($this->home, $e->getMessage());
            $this->assertStringNotContainsString(self::PLANTED_BODY, $e->getMessage());
        }
    }

    /** The sticky bit does not save it: `/tmp` is 1777 and that is the case measured. */
    public function testAStickyWorldWritableHomeIsStillRefused(): void
    {
        chmod($this->home, 0o1777);
        clearstatcache();

        $this->expectException(PermissionConfigException::class);

        Bootstrap::agentPresets($this->project);
    }

    /**
     * THE CONTROL, and it is the half that makes the refusal a fix rather than a
     * breakage: an ordinary 0700 home reads normally.
     */
    public function testAnOwnedHomeIsStillRead(): void
    {
        mkdir($this->home . '/.sugar-crush/agents', 0o700, true);
        file_put_contents(
            $this->home . '/.sugar-crush/agents/mine.md',
            "---\nname: mine\ndescription: my own roster\n---\nMINE-BODY\n",
        );

        $presets = Bootstrap::agentPresets($this->project);

        $this->assertArrayHasKey('mine', $presets);
    }

    /**
     * The second control, for the check's stated residual: `umask 002` layouts
     * give real homes mode 0775 and those keep working. Refusing them would
     * break installations to defend against a group the user already trusts.
     */
    public function testAGroupWritableHomeIsStillRead(): void
    {
        mkdir($this->home . '/.sugar-crush/agents', 0o700, true);
        file_put_contents(
            $this->home . '/.sugar-crush/agents/mine.md',
            "---\nname: mine\ndescription: my own roster\n---\nMINE-BODY\n",
        );
        chmod($this->home, 0o770);
        clearstatcache();

        $this->assertArrayHasKey('mine', Bootstrap::agentPresets($this->project));
    }
}
