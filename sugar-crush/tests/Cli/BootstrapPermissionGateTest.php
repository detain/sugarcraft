<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\PermissionConfigException;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\ToolCall;

/**
 * crush_code.md Phase 1 item 2's construction half: the launch's
 * {@see PermissionGate} is built here, from the same kebab-case
 * {@see PermissionMode} vocabulary agent presets already use, and threaded
 * into {@see EngineBackend::withPermissionGate()} and into the hook chain
 * {@see Chat} gates its own tool calls on.
 *
 * HOME is redirected at a temp dir for the whole class, same convention as
 * {@see BootstrapUserConfigTest}, so nothing here reads or writes the real
 * ~/.sugar-crush/config.json.
 */
final class BootstrapPermissionGateTest extends TestCase
{
    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;
    private string|false $originalMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_permission_gate_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);
        mkdir($this->tempDir . '/project', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');

        // BOTH forms, because half a sandbox is not a sandbox. Everything in
        // src/ now resolves `~` through {@see \SugarCraft\Crush\Support\HomeDirectory},
        // which reads `getenv()` — but a nested process or a third-party
        // library holding a `$_SERVER['HOME']` copy must not be left pointing
        // at the DEVELOPER's real ~/.claude/skills and ~/.config/opencode/agents,
        // which would make these tests depend on a machine rather than a fixture.
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';

        $this->originalMode = getenv('SUGARCRUSH_PERMISSION_MODE');
        putenv('SUGARCRUSH_PERMISSION_MODE');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        if ($this->originalMode !== false) {
            putenv('SUGARCRUSH_PERMISSION_MODE=' . $this->originalMode);
        } else {
            putenv('SUGARCRUSH_PERMISSION_MODE');
        }

        // chmod-000 fixtures are written by two of the tests below (one on the
        // file, one on the directory); make the tree removable again before
        // sweeping it.
        @chmod($this->tempDir . '/home/.sugar-crush', 0700);
        @chmod($this->tempDir . '/home/.sugar-crush/config.json', 0600);
        clearstatcache();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * The upgrade-safety guarantee: the main loop had no gate at all before
     * this, and every Ask-producing mode fails closed on the engine path
     * (nothing anywhere attaches an approver), so anything stricter than
     * BypassPermissions by default would turn "no permission system" into
     * "every write refused".
     */
    public function testDefaultsToBypassPermissionsWhenNothingIsConfigured(): void
    {
        $this->assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());
    }

    /**
     * What the default gate does and does not buy, stated honestly.
     *
     * It refuses the `rm -rf /` circuit-breaker command — but so does
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook}, which has been
     * in the built-in chain all along and denies far more broadly. With the
     * shipped empty rule set the default gate is therefore EQUAL to having no
     * gate, not stricter than it; see
     * {@see testTheDefaultGateAddsNothingTheBuiltInChainDidNotAlreadyRefuse()}
     * for the composed-chain proof. What it buys is reachability: a mode or a
     * rule in the config now decides something.
     */
    public function testTheDefaultGateStillRefusesTheCircuitBreakerCommand(): void
    {
        $gate = Bootstrap::permissionGate();

        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'rm -rf /'])),
        );
        $this->assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])),
        );
    }

    /**
     * The claim this file used to make — "BypassPermissions is still strictly
     * MORE guarded than no gate" — pinned at the layer where it is actually
     * decided, the COMPOSED chain a real launch runs, rather than at the gate
     * in isolation where it is trivially true and misleading.
     *
     * Every command the gate's narrow root/home breaker refuses is already
     * refused by `ConfirmRemoveHook`'s much broader recursive/force `rm`
     * regex, and that hook runs FIRST. So on the shipped default the verdict
     * with the gate installed is identical to the verdict without it, for
     * both destructive and benign calls. This test exists so that claim can
     * never quietly drift back into the docs: if a future change genuinely
     * makes the default gate add something, this goes red and the prose gets
     * rewritten deliberately.
     *
     * @dataProvider callsTheDefaultGateChangesNothingFor
     */
    public function testTheDefaultGateAddsNothingTheBuiltInChainDidNotAlreadyRefuse(string $tool, array $args): void
    {
        $context = $this->context($tool, $args);

        $withoutGate = new HookManager(new HookRegistry());
        $withoutGate->registerBuiltIns();

        $withGate = new HookManager(new HookRegistry());
        $withGate->registerBuiltIns();
        $withGate->register(new PermissionGateHook(Bootstrap::permissionGate()));

        $before = $withoutGate->preToolUse($context);
        $after = $withGate->preToolUse($context);

        $this->assertSame(
            $before->permitsExecution(),
            $after->permitsExecution(),
            "the default gate changed the verdict for {$tool} — the README/docblock claim needs rewriting",
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function callsTheDefaultGateChangesNothingFor(): array
    {
        return [
            'rm -rf /' => ['Bash', ['command' => 'rm -rf /']],
            'rm -fr /' => ['Bash', ['command' => 'rm -fr /']],
            'rm -rf ~' => ['Bash', ['command' => 'rm -rf ~']],
            'rm --no-preserve-root' => ['Bash', ['command' => 'rm -rf --no-preserve-root /']],
            'sudo rm -rf /' => ['Bash', ['command' => 'sudo rm -rf /']],
            'chained rm' => ['Bash', ['command' => 'cd / && rm -rf *']],
            'benign ls' => ['Bash', ['command' => 'ls -la']],
            'benign read' => ['Read', ['path' => 'README.md']],
            'benign edit' => ['Edit', ['path' => 'notes.txt', 'content' => 'hi']],
            'benign glob' => ['Glob', ['pattern' => '*.php']],
        ];
    }

    /**
     * Variant A of the fail-open matrix: a well-formed strict config is read
     * and applied. Every other variant below is this one, broken in exactly
     * one way, and must NOT come back as the permissive default.
     */
    public function testAWellFormedStrictConfigIsAppliedInFull(): void
    {
        Bootstrap::writeUserConfig([
            'permissionMode' => 'plan',
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'deny']],
        ]);

        $gate = Bootstrap::permissionGate();

        $this->assertSame(PermissionMode::Plan, $gate->mode());
        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Edit', ['path' => 'a.txt'])));
    }

    /**
     * Variant B: the same config with one trailing comma. `readUserConfig()`
     * answers `[]` for it, which used to make both `permissionMode` and
     * `permissionRules` vanish and drop the launch to the MOST PERMISSIVE
     * mode, with nothing on stderr — a user who configured `plan` plus a deny
     * rule was running fully ungated and could not tell.
     */
    public function testACorruptConfigRefusesToLaunchRatherThanFallingBackToBypass(): void
    {
        $this->writeRawConfig('{"permissionMode":"plan","permissionRules":[{"pattern":"Bash","action":"deny"}],}');

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/is not usable JSON/');

        Bootstrap::permissionGate();
    }

    /**
     * A BOM is an editor artifact, not a typo, and it is invisible in every
     * editor that writes one — so `json_last_error_msg()`'s bare "Syntax
     * error" sent the user hunting for a stray comma in a file that is
     * character-for-character correct. Still a hard failure (JSON does not
     * permit a BOM and this path may not guess at a policy); it just says what
     * is wrong.
     */
    public function testABomdConfigNamesTheByteOrderMarkRatherThanReportingASyntaxError(): void
    {
        $this->writeRawConfig("\xEF\xBB\xBF{\"permissionMode\":\"plan\"}");

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/byte-order mark/');

        Bootstrap::permissionGate();
    }

    /**
     * Same failure, different shape: valid JSON that is not an object at all.
     *
     * The list cases are the ones that matter here. `is_array()` cannot tell
     * `{}` from `[]` — PHP's decoder collapses both to `[]` — so a top-level
     * JSON LIST sailed straight through the "not an array" guard, had every
     * key in it discarded, and started on bypass-permissions in silence,
     * while the error string below claimed a branch that could never fire for
     * it. The scalar `"plan"` this test used to check on its own IS caught by
     * `is_array()`, which is why it passed for the wrong reason.
     *
     * @dataProvider topLevelsThatAreNotJsonObjects
     */
    public function testAConfigWhoseTopLevelIsNotAnObjectRefusesToLaunch(string $raw): void
    {
        $this->writeRawConfig($raw);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/not usable JSON/');

        Bootstrap::permissionGate();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function topLevelsThatAreNotJsonObjects(): array
    {
        return [
            'scalar string' => ['"plan"'],
            'number' => ['42'],
            'null' => ['null'],
            'empty list' => ['[]'],
            'list of strings' => ['["plan"]'],
            'list of objects' => ['[{"permissionMode":"plan","permissionRules":[{"pattern":"Bash","action":"deny"}]}]'],
            'indented list of objects' => ["\n  [{\"permissionMode\":\"plan\"}]"],
        ];
    }

    /**
     * Variant C: present, well-formed, and unreadable. Indistinguishable from
     * "absent" to a tolerant reader, and just as fail-open.
     */
    public function testAnUnreadableConfigRefusesToLaunchRatherThanFallingBackToBypass(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads a 0000 file, so the unreadable branch cannot be reached');
        }

        $this->writeRawConfig('{"permissionMode":"plan"}');
        chmod($this->tempDir . '/home/.sugar-crush/config.json', 0000);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        Bootstrap::permissionGate();
    }

    /**
     * Variant D, and the one the "unreadable config hard-fails" claim was
     * flatly wrong about: an unreadable config DIRECTORY.
     *
     * `is_file()` answers false when the parent directory cannot be searched,
     * exactly as it does when the file is absent — so a strict `plan` config
     * sitting behind a `chmod 000` directory silently became
     * bypass-permissions, exit 0, nothing on stderr. Note which way round that
     * used to be: an unreadable FILE refused to start while an unreadable
     * DIRECTORY, which hides strictly more, did not. It needs no chmod by the
     * user to reach — a different euid, `sudo` without `-E`, an NFS blip.
     */
    public function testAnUnsearchableConfigDirectoryRefusesToLaunch(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root searches a 0000 directory, so the branch cannot be reached');
        }

        $this->writeRawConfig('{"permissionMode":"plan","permissionRules":[{"pattern":"Bash","action":"deny"}]}');
        chmod($this->tempDir . '/home/.sugar-crush', 0000);
        clearstatcache();

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/cannot be reached/');

        Bootstrap::permissionGate();
    }

    /**
     * Variant E, and the same fail-open reached through a SYMLINK.
     *
     * The ancestor walk is lexical (`dirname()`), so it cannot see the real
     * chain past a link: point `~/.sugar-crush` at a directory sitting behind
     * an unsearchable one and `is_dir()` on the link answers false, the walk
     * steps up to a perfectly searchable `~`, and a `plan` policy nobody can
     * read is reported as "nothing configured" — bypass-permissions, exit 0,
     * nothing on stderr. Strictly narrower than the pre-fix hole and reached
     * the same way: a config dir symlinked into a tree this euid cannot enter.
     */
    public function testASymlinkedConfigDirectoryBehindAnUnsearchableTreeRefusesToLaunch(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root searches a 0000 directory, so the branch cannot be reached');
        }

        mkdir($this->tempDir . '/vault/real', 0700, true);
        file_put_contents(
            $this->tempDir . '/vault/real/config.json',
            '{"permissionMode":"plan","permissionRules":[{"pattern":"Bash","action":"deny"}]}',
        );
        symlink($this->tempDir . '/vault/real', $this->tempDir . '/home/.sugar-crush');
        chmod($this->tempDir . '/vault', 0000);
        clearstatcache();

        try {
            $this->expectException(PermissionConfigException::class);
            $this->expectExceptionMessageMatches('/cannot be reached/');

            Bootstrap::permissionGate();
        } finally {
            chmod($this->tempDir . '/vault', 0700);
            unlink($this->tempDir . '/home/.sugar-crush');
        }
    }

    /**
     * ...and the guard that keeps that from being an over-eager refusal: a
     * symlinked config directory that RESOLVES is an ordinary, supported
     * layout (a dotfiles repo checkout is the obvious one) and must be read
     * and honoured exactly as a real directory is.
     */
    public function testAWorkingSymlinkedConfigDirectoryIsStillHonoured(): void
    {
        mkdir($this->tempDir . '/dotfiles/sugar-crush', 0700, true);
        file_put_contents($this->tempDir . '/dotfiles/sugar-crush/config.json', '{"permissionMode":"plan"}');
        symlink($this->tempDir . '/dotfiles/sugar-crush', $this->tempDir . '/home/.sugar-crush');
        clearstatcache();

        try {
            $this->assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
        } finally {
            unlink($this->tempDir . '/home/.sugar-crush');
        }
    }

    /**
     * A config file that does not exist at all is NOT an error — a fresh
     * install has nothing to read and has to start. Absence and
     * present-but-unusable are the two cases this path separates.
     */
    public function testAnAbsentConfigIsNotAnError(): void
    {
        $this->assertFileDoesNotExist($this->tempDir . '/home/.sugar-crush/config.json');
        $this->assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());
    }

    /**
     * ...and neither is a config directory that does not exist yet, which is
     * the same case one level up. Without this, the unsearchable-ancestor
     * check above would be one over-eager `is_dir()` away from refusing to
     * launch on every fresh install.
     */
    public function testAnAbsentConfigDirectoryIsNotAnError(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir . '/home/.sugar-crush');
        $this->assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());
    }

    /**
     * A zero-byte config is ABSENCE, not corruption — and refusing to start on
     * it bricked the CLI on a state the CLI could produce itself: the
     * `writeUserConfig()` behind `/theme` and Ctrl+P was a plain
     * `file_put_contents()`, so a persist interrupted by SIGINT, an OOM kill
     * or a full disk left exactly this file and every subsequent launch died
     * at exit 2 with no way to fix it from inside the binary.
     *
     * @dataProvider configsThatSayNothing
     */
    public function testAnEmptyConfigStartsOnTheDefaultRatherThanBrickingTheCli(string $raw): void
    {
        $this->writeRawConfig($raw);

        $this->assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function configsThatSayNothing(): array
    {
        return [
            'zero bytes' => [''],
            'spaces' => ['   '],
            'newlines' => ["\n\n"],
            'mixed whitespace' => [" \t\r\n "],
        ];
    }

    /**
     * The other half of that fix: the write itself can no longer PRODUCE a
     * torn config. Asserted by proving the replacement goes through a rename
     * — the target's inode changes and no temp file is left behind — rather
     * than by trying to race a signal against a `file_put_contents()`.
     */
    public function testWriteUserConfigReplacesTheFileAtomically(): void
    {
        Bootstrap::writeUserConfig(['theme' => 'dark']);
        $path = Bootstrap::userConfigPath();
        clearstatcache();
        $firstInode = fileinode($path);

        Bootstrap::writeUserConfig(['theme' => 'light']);
        clearstatcache();

        $this->assertNotSame($firstInode, fileinode($path), 'the config must be replaced by rename(), not overwritten in place');
        $this->assertSame('light', Bootstrap::readUserConfig()['theme'] ?? null);

        // scandir(), not glob('*'): the temp file's name starts with a dot, so
        // a glob would report a clean directory however many were left.
        $entries = array_values(array_diff(scandir($this->tempDir . '/home/.sugar-crush') ?: [], ['.', '..']));
        $this->assertSame(['config.json'], $entries, 'a temp file was left behind next to the config');
    }

    /**
     * ...and that atomic write must not have made a NON-CANONICAL `HOME`
     * silently stop persisting anything at all.
     *
     * `tempnam()` hands back a canonical path, so the sibling check
     * (`dirname($temp) !== $dir`, which exists to catch tempnam's silent
     * fall-back to the system temp dir) was true for EVERY write once
     * `configDirPath()` yielded `/x/`, `/x//` or `/x/./` — `writeUserConfig()`
     * returned early, and `/theme`, Ctrl+P and every other persist became a
     * no-op with nothing on stderr. A regression from the atomic-write fix
     * specifically: the `file_put_contents()` it replaced handled `//` fine,
     * and `HOME=/root/` is ordinary in a Dockerfile.
     *
     * @dataProvider nonCanonicalHomes
     */
    public function testANonCanonicalHomeStillPersistsTheConfig(string $suffix): void
    {
        putenv('HOME=' . $this->tempDir . '/home' . $suffix);

        Bootstrap::writeUserConfig(['theme' => 'tokyonight']);

        clearstatcache();
        $this->assertFileExists($this->tempDir . '/home/.sugar-crush/config.json');
        $this->assertSame('tokyonight', Bootstrap::readUserConfig()['theme'] ?? null);

        $entries = array_values(array_diff(scandir($this->tempDir . '/home/.sugar-crush') ?: [], ['.', '..']));
        $this->assertSame(['config.json'], $entries, 'a temp file was left behind next to the config');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCanonicalHomes(): array
    {
        return [
            'trailing slash' => ['/'],
            'doubled slash' => ['//'],
            'dot segment' => ['/./'],
        ];
    }

    public function testPersistedConfigSelectsTheMode(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);

        $this->assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    public function testTheEnvVarOverridesThePersistedMode(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);
        putenv('SUGARCRUSH_PERMISSION_MODE=accept-edits');

        $this->assertSame(PermissionMode::AcceptEdits, Bootstrap::permissionGate()->mode());
    }

    /**
     * Variants E/F/G: `paln`, `Plan` (wrong case) and `deny-all`. All three
     * used to be discarded in silence, landing on the permissive default even
     * when a stricter mode was persisted underneath them.
     *
     * Falling THROUGH to the config was the old behaviour and is no better
     * than falling to the default: the user set the variable on purpose, we
     * cannot know what they meant, and every fallback in the chain ends
     * somewhere more permissive than a mode called `paln` was ever going to be.
     *
     * @dataProvider unusableModeValues
     */
    public function testAnUnrecognisedEnvValueRefusesToLaunch(string $value): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);
        putenv('SUGARCRUSH_PERMISSION_MODE=' . $value);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/SUGARCRUSH_PERMISSION_MODE/');

        Bootstrap::permissionGate();
    }

    /**
     * The same value written in the config file instead. Same reasoning: the
     * key is present, so something was meant by it.
     *
     * @dataProvider unusableModeValues
     */
    public function testAnUnrecognisedConfigValueRefusesToLaunch(string $value): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => $value]);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/permissionMode/');

        Bootstrap::permissionGate();
    }

    /**
     * A `permissionMode` of the wrong TYPE is the same fail-open one step
     * removed: an `is_string()` guard that skips it lands on the permissive
     * default just as silently as an unrecognised string used to.
     *
     * @dataProvider nonStringModeValues
     */
    public function testAConfigModeOfTheWrongTypeRefusesToLaunch(mixed $value): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => $value]);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/permissionMode/');

        Bootstrap::permissionGate();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonStringModeValues(): array
    {
        return [
            'number' => [42],
            'bool' => [true],
            'list' => [['plan']],
        ];
    }

    /**
     * ...but a `permissionMode` of `""` is absence, the same as an empty env
     * var: a key written and then blanked out says nothing.
     */
    public function testAnEmptyConfigModeIsTreatedAsAbsent(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => '']);

        $this->assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableModeValues(): array
    {
        return [
            'typo' => ['paln'],
            'wrong case' => ['Plan'],
            'plausible but invented' => ['deny-all'],
        ];
    }

    /**
     * An EMPTY env var is absence, not a bad value — `SUGARCRUSH_PERMISSION_MODE=`
     * is how a wrapper script unsets an inherited override, and must fall
     * through to the config rather than stopping the launch.
     */
    public function testAnEmptyEnvValueFallsThroughToTheConfig(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);
        putenv('SUGARCRUSH_PERMISSION_MODE=');

        $this->assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    public function testConfiguredRulesReachTheGate(): void
    {
        Bootstrap::writeUserConfig([
            'permissionMode' => 'bypass-permissions',
            'permissionRules' => [
                ['pattern' => 'Bash', 'action' => 'deny'],
            ],
        ]);

        $gate = Bootstrap::permissionGate();

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Read', [])));
    }

    /**
     * A malformed rule is DROPPED, never coerced: an entry whose action cannot
     * be read must not silently become an allow.
     *
     * Asserted under `plan`, NOT under `bypass-permissions`, and that is the
     * whole point of the test. Under bypass every tool is allowed anyway, so
     * "rule dropped" and "rule coerced to allow" produce the same verdict and
     * the assertion proves nothing. Under `plan` an `Edit` is denied by the
     * mode, so a coerced-to-allow rule would flip it — which is exactly the
     * failure the docblock claims cannot happen.
     */
    public function testMalformedRulesAreSkippedIndividually(): void
    {
        Bootstrap::writeUserConfig([
            'permissionMode' => 'plan',
            'permissionRules' => [
                'not-an-array',
                ['action' => 'deny'],
                ['pattern' => 'Read'],
                ['pattern' => 'Edit', 'action' => 'nonsense'],
                ['pattern' => 'Bash', 'action' => 'deny'],
            ],
        ]);

        $gate = Bootstrap::permissionGate();

        // The malformed Edit rule vanished, so plan mode's own Deny stands.
        // Coerce it to Allow and this line goes red.
        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Edit', ['path' => 'a.txt'])));
        // ...and the well-formed rule alongside it still took effect: plan
        // mode allows a non-writing Bash, this rule is what denies it.
        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
    }

    public function testAPermissionRulesKeyOfTheWrongTypeIsIgnored(): void
    {
        Bootstrap::writeUserConfig(['permissionRules' => 'nope']);

        $this->assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());
    }

    /**
     * The wiring claim itself: a backend built by Bootstrap carries the gate,
     * and carries the caller's instance rather than a second one — the
     * Auto-mode circuit breaker is per-instance state.
     */
    public function testBackendCarriesTheGateItWasGiven(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        $backend = Bootstrap::backend($this->tempDir . '/project', null, $gate);

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame($gate, $this->gateOf($backend));
    }

    public function testBackendBuildsItsOwnGateWhenTheCallerPassesNone(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);

        $backend = Bootstrap::backend($this->tempDir . '/project');

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame(PermissionMode::Plan, $this->gateOf($backend)?->mode());
    }

    /**
     * The OTHER construction path, and the one every run with
     * `$SUGARCRUSH_PROVIDER` set — plus every one-shot `-p` run through
     * {@see \SugarCraft\Crush\Cli\NonInteractive} — actually takes.
     *
     * `backend()`'s tests say nothing about it: with no `$SUGARCRUSH_PROVIDER`
     * and no persisted provider, `backend()` builds the Echo engine itself and
     * never delegates here. Deleting `backendFor()`'s
     * `->withPermissionGate(...)` left the whole `tests/Cli/` suite green.
     *
     * `custom` is the one built-in provider type `ProviderFactory` can
     * construct with no credential in the environment.
     */
    public function testBackendForCarriesTheGateItWasGiven(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        $backend = Bootstrap::backendFor('custom', $this->tempDir . '/project', null, $gate);

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame($gate, $this->gateOf($backend));
    }

    public function testBackendForBuildsItsOwnGateWhenTheCallerPassesNone(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);

        $backend = Bootstrap::backendFor('custom', $this->tempDir . '/project');

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame(PermissionMode::Plan, $this->gateOf($backend)?->mode());
    }

    /**
     * ...and an unusable permission policy stops `backendFor()` too, rather
     * than being reported as the named provider's fault.
     */
    public function testBackendForRefusesToBuildOnAnUnusablePermissionPolicy(): void
    {
        $this->writeRawConfig('{"permissionMode":"paln"}');

        $this->expectException(PermissionConfigException::class);

        Bootstrap::backendFor('custom', $this->tempDir . '/project');
    }

    /**
     * The OTHER live tool path. `Chat::gateToolCall()` runs Chat's own
     * registered tools through the hook chain, and it is reached without ever
     * touching {@see EngineBackend} — so a backend that carries the gate says
     * nothing about whether Chat does. Before this test, deleting the
     * `register(new PermissionGateHook(...))` line from `Bootstrap::hooks()`
     * left the entire suite green.
     *
     * Asserted through the chain's own verdict rather than by reflecting on
     * the registry: what matters is that a call the mode forbids is actually
     * refused on this path.
     */
    public function testChatsOwnHookChainCarriesTheGate(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);

        $chat = Bootstrap::chat($this->tempDir . '/project');
        $hooks = $chat->hooks();

        $this->assertInstanceOf(HookManager::class, $hooks);

        // A perfectly ordinary edit of a perfectly ordinary file: no built-in
        // hook objects to it, and plan mode forbids every write.
        $result = $hooks->preToolUse($this->context('Edit', [
            'path' => $this->tempDir . '/project/notes.txt',
            'content' => 'hello',
        ]));

        $this->assertFalse($result->permitsExecution(), 'Chat\'s hook chain must gate on the permission mode');
        $this->assertStringContainsString("mode 'plan'", $result->message);
    }

    /**
     * ONE gate for the whole launch, which is what `Bootstrap::chat()`'s
     * comment claims and nothing checked. Two independent instances each keep
     * their own Auto-mode strike counter, so the 3-strike circuit breaker
     * would need six strikes to trip and a user watching one counter would be
     * watching half the session's refusals.
     */
    public function testTheEngineAndChatShareOneGateInstance(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/project');

        $backend = $chat->backend();
        $this->assertInstanceOf(EngineBackend::class, $backend);

        $engineGate = $this->gateOf($backend);
        $this->assertInstanceOf(PermissionGate::class, $engineGate);

        $hooks = $chat->hooks();
        $this->assertInstanceOf(HookManager::class, $hooks);

        $this->assertSame(
            $engineGate,
            $this->gateInstalledOn($hooks),
            'Bootstrap::chat() must build ONE PermissionGate and hand the same instance to both tool paths',
        );
    }

    /**
     * ...and it stays one gate across a Ctrl+P "Switch model", which builds a
     * whole new backend.
     *
     * `Chat::selectPaletteProvider()` called `Bootstrap::backendFor($name)`
     * with no gate, so the engine came back holding a freshly-constructed
     * second one: in `auto` mode the 3-strike/20-total counters are
     * per-instance, so a model sitting at two strikes got a clean slate and
     * escalation-to-Ask never fired, and a config edited in between put the
     * two live tool paths on two different modes. The invariant the design
     * leans on was pinned only at construction, which is the one moment it
     * could not fail.
     */
    public function testTheGateSurvivesAProviderSwitch(): void
    {
        putenv('CUSTOM_API_KEY=test-key');

        try {
            $chat = Bootstrap::chat($this->tempDir . '/project');
            $before = $this->gateInstalledOn($this->hooksOf($chat));
            $this->assertInstanceOf(PermissionGate::class, $before);

            $switch = new \ReflectionMethod(Chat::class, 'selectPaletteProvider');
            [$next] = $switch->invoke($chat, 'custom');
            $this->assertInstanceOf(Chat::class, $next);

            $backend = $next->backend();
            $this->assertInstanceOf(EngineBackend::class, $backend);
            $this->assertNotSame($chat->backend(), $backend, 'the switch must actually have replaced the backend');

            $this->assertSame(
                $before,
                $this->gateOf($backend),
                'a provider switch must carry the launch gate, not rebuild one',
            );
        } finally {
            putenv('CUSTOM_API_KEY');
        }
    }

    /**
     * Sub-agent gates come from the same construction point now, which is what
     * fixes a preset declaring `permissionMode: auto`: AgentManager's own bare
     * `new PermissionGate($mode)` fallback passes no SafetyClassifier, and
     * PermissionGate::evaluateAuto() fails closed to Ask without one — so Auto
     * asked about every single call instead of classifying any of them.
     */
    public function testAgentManagerSubAgentsGetAnAutoModeGateThatCanActuallyClassify(): void
    {
        // `coder` is one of the six built-in roster entries agentManager()
        // registers, so this exercises the real construction path rather than
        // a hand-registered stand-in.
        $manager = Bootstrap::agentManager($this->tempDir . '/project');

        $subAgent = $manager->createSubAgent('coder', 'do the thing', PermissionMode::Auto);

        $this->assertNotNull($subAgent->permissionGate);
        $this->assertSame(PermissionMode::Auto, $subAgent->permissionGate->mode());
        $this->assertSame(
            PermissionDecision::Allow,
            $subAgent->permissionGate->evaluate(new ToolCall('Bash', ['command' => 'ls'])),
            'a safe command must be classified as safe, not escalated to a prompt nobody can answer',
        );
    }

    /**
     * `/agents` has no caller in `src/` yet, so the sub-agent gate factory is
     * dormant — but it is one dispatch away from being live, and it used to
     * read the permission config LAZILY, inside the closure. That put a
     * PermissionConfigException at `createSubAgent()` time: mid-TUI, where its
     * only handler is `bin/sugarcrush`'s and its exit(2) would abandon the
     * terminal in alt-screen/raw mode. The read is eager now, so the refusal
     * lands on the launch path with every other one.
     */
    public function testAnUnusablePolicyStopsAgentManagerAtConstructionNotAtSubAgentTime(): void
    {
        $this->writeRawConfig('{"permissionRules":[{"pattern":"Bash","action":"deny"}],}');

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/not usable JSON/');

        Bootstrap::agentManager($this->tempDir . '/project');
    }

    /**
     * The consequence of that same eager read, stated as behaviour: a config
     * broken UNDERNEATH a running session cannot throw out of the sub-agent
     * factory, because the factory no longer touches the disk. Sub-agents get
     * the policy the launch started with — the same "one config source for the
     * whole launch" the main-loop gate commits to.
     */
    public function testSubAgentsUseTheLaunchsPolicyEvenIfTheConfigBreaksMidSession(): void
    {
        Bootstrap::writeUserConfig([
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'deny']],
        ]);

        $manager = Bootstrap::agentManager($this->tempDir . '/project');

        $this->writeRawConfig('{ this is not json');

        $subAgent = $manager->createSubAgent('coder', 'do the thing', PermissionMode::BypassPermissions);

        $this->assertNotNull($subAgent->permissionGate);
        $this->assertSame(
            PermissionDecision::Deny,
            $subAgent->permissionGate->evaluate(new ToolCall('Bash', ['command' => 'ls'])),
            'the rule read at launch must still apply after the config on disk broke',
        );
    }

    private function gateOf(EngineBackend $backend): ?PermissionGate
    {
        $property = new \ReflectionProperty(EngineBackend::class, 'permissionGate');

        return $property->getValue($backend);
    }

    /**
     * The gate a {@see HookManager} has a {@see PermissionGateHook} for, or
     * null when it has none. Reflection because HookManager keeps its registry
     * private and exposes no reader for it.
     */
    private function hooksOf(Chat $chat): HookManager
    {
        $hooks = $chat->hooks();
        $this->assertInstanceOf(HookManager::class, $hooks);

        return $hooks;
    }

    private function gateInstalledOn(HookManager $manager): ?PermissionGate
    {
        $registry = (new \ReflectionProperty(HookManager::class, 'registry'))->getValue($manager);
        $this->assertInstanceOf(HookRegistry::class, $registry);

        $hook = $registry->get('PreToolUse', PermissionGateHook::NAME);

        return $hook instanceof PermissionGateHook ? $hook->gate() : null;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function context(string $tool, array $args): HookContext
    {
        return new HookContext(
            sessionId: 'test-session',
            toolName: $tool,
            toolArgs: $args,
            toolInput: json_encode($args) ?: '{}',
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->tempDir . '/project',
        );
    }

    /**
     * Write the config file byte-for-byte, bypassing
     * {@see Bootstrap::writeUserConfig()} — which can only ever emit valid
     * JSON, and valid JSON is precisely what these cases are not.
     */
    private function writeRawConfig(string $contents): void
    {
        $dir = $this->tempDir . '/home/.sugar-crush';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($dir . '/config.json', $contents);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
