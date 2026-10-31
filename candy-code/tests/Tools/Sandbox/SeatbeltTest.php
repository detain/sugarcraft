<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\Sandbox\JailPaths;
use SugarCraft\Code\Tools\Sandbox\Seatbelt;

/**
 * C-TOOL-4.b: the macOS Seatbelt backend.
 *
 * The profile TEXT is the security: SBPL answers with the LAST rule that
 * matches, so these pins assert ORDER, not mere presence — a reordered
 * profile that denies before it allows would look identical to a grep for
 * the right lines and jail nothing.
 *
 * NO SKIPS, ON PURPOSE (the same law {@see \SugarCraft\Code\Tests\Tools\BashSandboxTest}
 * states): the skip roster reds on an unrostered skip, and the macos-smoke
 * CI leg is the only place a real `sandbox-exec` runs. So every test here
 * answers on both host families: the profile-text pins are OS-independent
 * by construction, and the live legs assert BOTH arms — on Linux the
 * macOS-only gate refuses deterministically before any exec; on macOS the
 * real binary takes the confine-or-refuse arms exactly as the bwrap test
 * does.
 */
final class SeatbeltTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir = '';
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/seatbelt_' . getmypid() . '_' . uniqid('', true);
        $this->root = $this->tmpDir . '/repo';
        $home = $this->tmpDir . '/home';
        mkdir($this->root, 0o700, true);
        mkdir($home . '/.candy-code', 0o700, true);
        $this->root = (string) realpath($this->root);

        $this->useHomeSandbox($home);
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
        $this->restoreHomeSandbox();

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    // ─── argv shape ──────────────────────────────────────────────────────

    public function testTheArgvEndsAtTheParametersAndCarriesNoSeparator(): void
    {
        // sandbox-exec execs whatever follows its options — a trailing '--'
        // would be exec'd as a literal command name. Callers append the
        // command themselves (wrap(), or the PtyRun spawn prefix).
        $argv = Seatbelt::new('/usr/bin/sandbox-exec')->argv($this->root, false, '');

        self::assertSame('/usr/bin/sandbox-exec', $argv[0]);
        self::assertSame('-p', $argv[1]);
        self::assertIsString($argv[2]);
        self::assertNotContains('--', $argv, 'sandbox-exec would exec a literal "--" as the command');

        $tail = \array_slice($argv, 3);
        self::assertSame(0, count($tail) % 2, 'the tail is -D/value pairs');
        for ($i = 0; $i < count($tail); $i += 2) {
            self::assertSame('-D', $tail[$i], 'flag leg at tail offset ' . $i);
            self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*=/', $tail[$i + 1], 'name leg at tail offset ' . $i);
        }
    }

    public function testAChdirDifferentFromTheRootIsRefusedNotDropped(): void
    {
        $sandbox = Seatbelt::new('/usr/bin/sandbox-exec');

        // sandbox-exec has no chdir; the caller's cd guard rides the command
        // (Bash wraps `cd <root> && …`). A $chdir equal to the root in any
        // spelling is honest; anything else would run the command in a
        // directory its caller did not jail it for — silently.
        $trailingSlash = $sandbox->argv($this->root, false, '', $this->root . '/');
        self::assertSame('-p', $trailingSlash[1]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot change directory');
        $sandbox->argv($this->root, false, '', $this->root . '/elsewhere');
    }

    // ─── the profile text: order IS the design ───────────────────────────

    public function testTheProfileDeniesWritesBeforeItAllowsAndShutsHatchesAfter(): void
    {
        mkdir($this->root . '/.git', 0o700, true);
        [$profile, $params] = $this->profileOf($this->root);
        $lines = explode("\n", $profile);
        $where = $this->lineFinder($lines);

        // The blanket write deny is one exact line — match it, not its
        // substring (the hatch denies share the '(deny file-write*' prefix).
        $denyAll = -1;
        foreach ($lines as $i => $line) {
            if ($line === '(deny file-write*)') {
                $denyAll = $i;
            }
        }
        self::assertGreaterThanOrEqual(0, $denyAll, 'the blanket file-write deny exists');
        self::assertLessThan($denyAll, $where('(allow default)'), 'the blanket allow opens the profile');
        self::assertLessThan($denyAll, $where('(version 1)'));

        $rootAllow = $where('(param "ROOT")');
        self::assertGreaterThan($denyAll, $rootAllow, 'the root allow must FOLLOW the blanket deny');
        self::assertStringContainsString('(allow file-write*', $lines[$rootAllow]);

        // Every escape hatch is a DENY and comes after the root allow — last
        // match wins, so a hatch that drifted above the allow stops meaning
        // anything. Paths arrive as parameters; match them there. The name
        // counter is SHARED across git hatches and protected roots, so with
        // this .git directory present the plain-path hatches continue at 2.
        $hatchNames = ['GITHATCH_0', 'GITHATCH_1', 'PROTECT_2', 'PROTECT_3'];
        self::assertSame(JailPaths::normalise($this->root . '/.git/hooks'), $params['GITHATCH_0'] ?? null);
        self::assertSame(JailPaths::normalise($this->root . '/.git/config'), $params['GITHATCH_1'] ?? null);
        self::assertSame($this->root . '/.candy-code', $params['PROTECT_2'] ?? null);
        self::assertSame($this->root . '/.mcp.json', $params['PROTECT_3'] ?? null);
        foreach ($hatchNames as $name) {
            $at = $where('(param "' . $name . '")');
            self::assertGreaterThan($rootAllow, $at, $name . ' must be denied AFTER the root allow');
            self::assertStringContainsString('(deny file-write*', $lines[$at]);
        }
    }

    public function testEveryPathArrivesAsAParameterNeverInlined(): void
    {
        // A repository root is data; quoting it into SBPL — which has its own
        // #"..."# literals — is a parsing decision no path content gets to
        // make. The profile text may only ever name (param "NAME").
        $weirdRoot = $this->tmpDir . '/seatbelt-weird(#"root';
        mkdir($weirdRoot, 0o700, true);
        $weirdRoot = (string) realpath($weirdRoot);

        [$profile, $params] = $this->profileOf($weirdRoot);
        self::assertSame($weirdRoot, $params['ROOT'] ?? null, 'the parameter carries the raw path');
        self::assertFalse(str_contains($profile, $weirdRoot), 'the profile text never inlines it');
        self::assertStringContainsString('(allow file-write* (subpath (param "ROOT")))', $profile);
    }

    public function testTheTmpArmExistsOnlyForRealDistinctDirectories(): void
    {
        $sandbox = Seatbelt::new('/usr/bin/sandbox-exec');

        // A temp dir the jail can already write (or that does not exist)
        // needs no rule of its own; the skip is on the NORMALISED answer,
        // which is why '/tmp' and '/private/tmp' both drop out on macOS and
        // only one of them means anything on Linux.
        foreach (['', '/', '/tmp', Seatbelt::PRIVATE_TMP] as $useless) {
            [$profile, $params] = $this->profileOf($this->root, $useless);
            self::assertArrayNotHasKey('TMP', $params, $useless . ' needs no rule');
            self::assertStringNotContainsString('(param "TMP")', $profile);
        }

        $real = $this->tmpDir . '/app-tmp';
        mkdir($real, 0o700, true);
        [$profile, $params] = $this->profileOf($this->root, $real);
        self::assertSame(JailPaths::normalise($real), $params['TMP'] ?? null);
        $lines = explode("\n", $profile);
        self::assertStringContainsString('(allow file-write* (subpath (param "TMP")))', $profile);
        $rootAllow = null;
        foreach ($lines as $i => $line) {
            $rootAllow ??= str_contains($line, '(param "ROOT")') ? $i : null;
        }
        self::assertIsInt($rootAllow);
    }

    public function testTheSharedTempAndDeviceAllowsAreAlwaysThere(): void
    {
        [$profile] = $this->profileOf($this->root);

        self::assertStringContainsString('(allow file-write* (subpath "' . Seatbelt::PRIVATE_TMP . '"))', $profile);
        self::assertStringContainsString('(allow file-write* (subpath "' . Seatbelt::PRIVATE_FOLDERS . '"))', $profile);
        self::assertStringContainsString('(allow file-write* (literal "/dev/null"))', $profile);
        self::assertStringContainsString('(allow file-write* (regex #"^/dev/ttys"))', $profile);
    }

    // ─── git directories: the .git FILE is repository content ────────────

    public function testAPlantedGitdirFileBindsNothingWritable(): void
    {
        // A checkout committing `gitdir: /home/nowhere` must not make the
        // jail bind anything outside itself: the back-pointer check in
        // JailPaths refuses, and with it every GIT/GITHATCH rule.
        file_put_contents($this->root . '/.git', "gitdir: /home/nowhere/.git\n");

        [, $params] = $this->profileOf($this->root);
        foreach ($params as $name => $value) {
            self::assertFalse(str_starts_with($name, 'GIT_'), $name . ' must not exist for a planted gitdir');
            self::assertFalse(str_starts_with($name, 'GITHATCH_'), $name . ' must not exist for a planted gitdir');
            self::assertStringNotContainsString('/home/nowhere', $value);
        }
        // The root's own hatches are still shut — that arm is unconditional.
        self::assertArrayHasKey('PROTECT_0', $params);
    }

    public function testALinkedWorktreeBindsItsGitdirsWritableWithTheHatchesDenied(): void
    {
        $common = $this->tmpDir . '/common';
        $gitDir = $common . '/worktrees/wt';
        mkdir($gitDir, 0o700, true);
        file_put_contents($common . '/HEAD', 'ref: refs/heads/main' . "\n");
        file_put_contents($gitDir . '/gitdir', $this->root . '/.git' . "\n");
        file_put_contents($this->root . '/.git', 'gitdir: ' . $gitDir . "\n");

        [$profile, $params] = $this->profileOf($this->root);
        $lines = explode("\n", $profile);
        $where = $this->lineFinder($lines);

        self::assertSame(JailPaths::normalise($gitDir), $params['GIT_0'] ?? null, 'the linked gitdir gets its own allow');
        self::assertSame(JailPaths::normalise($common), $params['GIT_3'] ?? null, 'the common dir too — commits write HEAD there');
        self::assertStringContainsString('(allow file-write*', $lines[$where('(param "GIT_0")')]);
        self::assertStringContainsString('(allow file-write*', $lines[$where('(param "GIT_3")')]);

        foreach (['GITHATCH_1', 'GITHATCH_2', 'GITHATCH_4', 'GITHATCH_5'] as $name) {
            $at = $where('(param "' . $name . '")');
            self::assertStringContainsString('(deny file-write*', $lines[$at], $name . ' must be a deny');
        }
        self::assertSame(JailPaths::normalise($gitDir . '/hooks'), $params['GITHATCH_1'] ?? null);
        self::assertSame(JailPaths::normalise($gitDir . '/config'), $params['GITHATCH_2'] ?? null);
        self::assertSame(JailPaths::normalise($common . '/hooks'), $params['GITHATCH_4'] ?? null);
        self::assertSame(JailPaths::normalise($common . '/config'), $params['GITHATCH_5'] ?? null);

        self::assertGreaterThan($where('(param "GIT_0")'), $where('(param "GITHATCH_1")'), 'the hatch deny must FOLLOW its allow — last match wins');
        self::assertGreaterThan($where('(param "GIT_3")'), $where('(param "GITHATCH_4")'));
    }

    public function testExtraReadOnlyBindsAreDeniedAfterEveryAllow(): void
    {
        $secret = $this->tmpDir . '/secret-dir';
        mkdir($secret, 0o700, true);

        [$profile, $params] = $this->profileOf($this->root, '', null, [$secret . '/']);
        self::assertSame(JailPaths::normalise($secret), $params['RO_0'] ?? null, 'the bind arrives normalised as a parameter');
        $lines = explode("\n", $profile);
        $where = $this->lineFinder($lines);
        $at = $where('(param "RO_0")');
        self::assertGreaterThan($where('(param "ROOT")'), $at);
        self::assertGreaterThan($where('(param "PROTECT_0")'), $at, 'explicit binds are denied last — the caller asked most recently');
        self::assertStringContainsString('(deny file-write*', $lines[$at]);
    }

    // ─── network arm ─────────────────────────────────────────────────────

    public function testNoNetworkDeniesIpButKeepsLocalUnixSockets(): void
    {
        [$open] = $this->profileOf($this->root, '', null, [], true);
        self::assertStringNotContainsString('(deny network', $open);

        [$shut] = $this->profileOf($this->root, '', null, [], false);
        $lines = explode("\n", $shut);
        $where = $this->lineFinder($lines);
        $denyAt = -1;
        foreach ($lines as $i => $line) {
            if ($line === '(deny network*)') {
                $denyAt = $i;
            }
        }
        self::assertGreaterThanOrEqual(0, $denyAt, 'the no-network profile denies network*');
        $outbound = $where('(allow network-outbound (local unix-socket))');
        $bind = $where('(allow network-bind (local unix-socket))');
        self::assertGreaterThan($denyAt, $outbound, 'the unix-socket re-allows must FOLLOW the deny');
        self::assertGreaterThan($denyAt, $bind);
    }

    // ─── setting + prose ─────────────────────────────────────────────────

    public function testTheSettingVocabularyOnThisBackend(): void
    {
        self::assertNull(Seatbelt::fromSetting(null));
        self::assertNull(Seatbelt::fromSetting('off'));
        self::assertNull(Seatbelt::fromSetting(false));
        self::assertSame('on', Seatbelt::fromSetting('on')?->mode());
        self::assertSame('on', Seatbelt::fromSetting(true)?->mode());
        self::assertSame('no-network', Seatbelt::fromSetting('no-network')?->mode());
        self::assertSame('no-network', Seatbelt::fromSetting('no-netwrok')?->mode(), 'an unrecognised value is the strictest mode, never off');
    }

    public function testTheDescriptionTellsTheTruthAboutWhatSeatbeltCannotDo(): void
    {
        $on = Seatbelt::new('', true)->describe($this->root);

        self::assertStringContainsString('macOS Seatbelt sandbox', $on);
        self::assertStringContainsString('"Operation not permitted"', $on, 'the model acts on this sentence; bwrap answers Read-only file system');
        self::assertStringContainsString(JailPaths::normalise($this->root), $on);
        self::assertStringNotContainsString('NO network', $on);

        $shut = Seatbelt::new('', false)->describe($this->root);
        self::assertStringContainsString('there is NO network access (local unix sockets stay open)', $shut);
        self::assertStringContainsString('"Operation not permitted"', $shut);
    }

    // ─── the probe, and the real binary: both arms, never skipped ────────

    public function testTheProbeGatesTheOsBeforeTheBinaryAndTheMemo(): void
    {
        // The synthetic binary isolates the verdict memo on macOS: a fake
        // answer stored under the REAL binary's key would poison the live
        // jail legs of every later test — and the tool's own refusals.
        $sentinel = '/seatbelt-test-probe-absent-' . uniqid('', true);
        $stderr = 'seatbelt-sentinel-' . uniqid('', true);
        $called = 0;

        $refusal = Seatbelt::new($sentinel)->probe(
            static function (string $command) use (&$called, $stderr): array {
                ++$called;

                return ['exitCode' => 1, 'stderr' => $stderr];
            },
        );

        if (\PHP_OS_FAMILY === 'Darwin') {
            self::assertSame(1, $called, 'on macOS the probe reaches the exec path');
            self::assertSame($stderr, $refusal, 'a failing probe answers with the stderr verbatim');

            return;
        }

        self::assertSame(0, $called, 'the OS gate must fire before any exec path');
        self::assertSame('seatbelt (sandbox-exec) is macOS-only and this host is ' . \PHP_OS_FAMILY, $refusal);
    }

    public function testTheRealSeatbeltRefusesOrRunsThroughBashAndNeverPartially(): void
    {
        // The integration arm of the macOS backend. On this Linux host the
        // probe's OS gate makes Bash refuse BEFORE anything spawns: nothing
        // runs, neither inside nor outside. On macOS the real sandbox-exec
        // runs under the very profile the text pins above describe — the
        // working root is writable and the command completes. (Seatbelt
        // shares /tmp by design — no private namespaces — so the outside
        // marker is only an absence assertion where the jail never runs it;
        // the deny-after-allow hatches are pinned host-independently by the
        // profile-text tests, not by this live leg.)
        $outside = $this->tmpDir . '/seatbelt-outside-marker';
        $bash = new Bash($this->root, sandbox: Seatbelt::new());

        $result = $bash->execute([
            'command' => 'touch inside-marker; touch ' . escapeshellarg($outside) . ' 2>/dev/null; echo ran',
            'description' => 'probe',
        ]);
        $content = $result->content();

        if (str_contains($content, 'Not run:')) {
            self::assertTrue($result->isError());
            self::assertStringContainsString('sandbox cannot start on this host', $content);
            self::assertStringContainsString('seatbelt', $content, 'the refusal names the backend, not a hard-coded bwrap');
            if (\PHP_OS_FAMILY !== 'Darwin') {
                // Off macOS the refusal IS the OS gate. On Darwin a refusal
                // carries sandbox-exec's own stderr instead, so only the
                // shared sentence above may be asserted there — this leg
                // must never false-red the macOS smoke job.
                self::assertStringContainsString('macOS-only', $content);
            }
            self::assertFileDoesNotExist($this->root . '/inside-marker', 'a refused command must not have run');
            self::assertFileDoesNotExist($outside);

            return;
        }

        // The jail started. On Linux that is itself the bug this pin exists
        // for: the macOS backend must fail closed here.
        self::assertSame('Darwin', \PHP_OS_FAMILY, 'the Seatbelt jail cannot start off macOS: ' . $content);
        self::assertStringContainsString('ran', $content);
        self::assertFileExists($this->root . '/inside-marker', 'the working root is writable inside the jail');
        self::assertSame('seatbelt', $bash->sandbox()?->name());
    }

    /**
     * @param array<int, string> $readOnlyBinds
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function profileOf(string $root, string $tmpDir = '', ?string $chdir = null, array $readOnlyBinds = [], bool $network = true): array
    {
        $argv = Seatbelt::new('/usr/bin/sandbox-exec')->withNetwork($network)->argv($root, false, $tmpDir, $chdir, $readOnlyBinds);

        $params = [];
        $tail = \array_slice($argv, 3);
        for ($i = 0; $i < count($tail); $i += 2) {
            [$k, $v] = explode('=', $tail[$i + 1], 2);
            $params[$k] = $v;
        }

        return [$argv[2], $params];
    }

    /**
     * @param list<string> $lines
     */
    private function lineFinder(array $lines): \Closure
    {
        return static function (string $needle) use ($lines): int {
            foreach ($lines as $i => $line) {
                if (str_contains($line, $needle)) {
                    return $i;
                }
            }
            self::fail('no profile line contains: ' . $needle);
        };
    }
}
