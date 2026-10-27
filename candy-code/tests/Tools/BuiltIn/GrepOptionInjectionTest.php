<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Tools\BuiltIn\Grep;
use SugarCraft\Code\Tools\IgnoreRules;

/**
 * Audit F-J1 — model-supplied Grep arguments must never reach grep as options.
 *
 * The defect: execute() built `grep -rn … '<pattern>' '<path>'` with no `-e`
 * and no `--`. escapeshellarg() stops shell injection, not option injection,
 * so a pattern of `-ReSECRET` was parsed as `-R` (dereference every symlink)
 * plus `-e SECRET`, and grep walked a repo-shipped `link -> ../outside` out of
 * the PathJail; `-f FILE`, `--devices=read` and friends were reachable the
 * same way.
 *
 * The fixture is that attack in miniature: a workspace root holding a symlink
 * to a sibling directory with a secret in it. Every case asserts BOTH halves:
 * the secret never appears, AND the option-shaped pattern was searched for as
 * TEXT — the second half is what fails on the old command line for every
 * spelling, including the ones (`-R`, `--devices=read`) that would otherwise
 * have quietly consumed the path operand as their pattern.
 *
 * SHL-4 re-runs every argv-bearing case under rg as well, because F-J1 was
 * proved against grep's parser only: rg gets the same three guards (`-e` for
 * the pattern, single-token `--glob=<value>` for every model string, `--`
 * before the operand), each verified by measurement on rg 14.1.0, and the
 * `-r`-not-`-R` doctrine lands as rg's own default — walk-time symlinks are
 * never followed and `--follow` is deliberately never passed (a link named on
 * the command line IS followed on both engines, measured: that parity is the
 * bounded IgnoreRules::halts() hatch, not an accident). Not passing it is not
 * the whole doctrine: since C-TOOL-1.b the operator's RIPGREP_CONFIG_PATH
 * file cannot smuggle it either — `--no-config` leads the rg line and the
 * variable is blanked in the child's environment (see
 * {@see testRipgrepConfigFileCannotWidenTheWalk()}). The rg legs join the
 * dataset only on hosts with rg, via the same boot probe the wiring uses.
 * The non-string-argument guard test stays single-engine: it answers before
 * any command exists, which is the whole point of it.
 *
 * The process cwd is moved into the root for each test: an option-injected
 * call that swallows the path operand makes `grep -r` default to `.`, and
 * pinning `.` to the fixture keeps a regression bounded to it rather than to
 * whatever directory phpunit was launched from.
 */
final class GrepOptionInjectionTest extends TestCase
{
    private const SECRET = 'SECRET_TOKEN=hunter2';

    private string $base = '';
    private string $root = '';
    private string $patternFile = '';
    private string $previousCwd = '';

    protected function setUp(): void
    {
        $this->base = realpath(sys_get_temp_dir()) . '/crush-grep-optinj-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/root';
        mkdir($this->root, 0o777, true);
        mkdir($this->base . '/outside', 0o777, true);

        file_put_contents($this->base . '/outside/creds.txt', self::SECRET . "\n");
        // A pattern list an `-f` injection would read: it matches the secret.
        $this->patternFile = $this->base . '/outside/patterns.txt';
        file_put_contents($this->patternFile, "SECRET\n");
        // What a cloned hostile repo can ship: git stores symlinks verbatim.
        symlink('../outside', $this->root . '/innocent_link');

        file_put_contents($this->root . '/inner.txt', "a needle in the root\n");

        $this->previousCwd = (string) getcwd();
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== '') {
            chdir($this->previousCwd);
        }
        self::removeTree($this->base);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function optionShapedPatterns(): array
    {
        return [
            '-R plus -e, matching anything' => ['-Re.'],
            '-R plus -e SECRET (the audit repro)' => ['-ReSECRET'],
            'bare -R' => ['-R'],
            'long dereference flag' => ['--dereference-recursive'],
            'devices=read' => ['--devices=read'],
            'pattern file' => ['-f%PATTERN_FILE%'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function optionShapedPatternsUnderBothEngines(): array
    {
        return self::crossProduct(self::optionShapedPatterns());
    }

    #[DataProvider('optionShapedPatternsUnderBothEngines')]
    public function testAnOptionShapedPatternIsSearchedForAsTextAndNeverEscapesTheRoot(string $pattern, bool $rg): void
    {
        $pattern = str_replace('%PATTERN_FILE%', $this->patternFile, $pattern);
        file_put_contents($this->root . '/flags.txt', $pattern . "\n");

        $content = (new Grep($this->root, rgAvailable: $rg))->execute([
            'pattern' => $pattern,
            'path' => '.',
            'description' => 'option-shaped pattern',
        ])->content();

        $this->assertStringNotContainsString('hunter2', $content);
        $this->assertStringNotContainsString('innocent_link', $content);
        $this->assertStringNotContainsString('creds.txt', $content);
        $this->assertStringContainsString($this->root . '/flags.txt:1:' . $pattern, $content);
    }

    #[DataProvider('engines')]
    public function testALiteralDashPatternMatchesItsOwnText(bool $rg): void
    {
        file_put_contents($this->root . '/dash.txt', "keep\n-foo marks the spot\n");

        $content = (new Grep($this->root, rgAvailable: $rg))->execute([
            'pattern' => '-foo',
            'path' => '.',
            'description' => 'leading dash pattern',
        ])->content();

        $this->assertStringContainsString($this->root . '/dash.txt:2:-foo marks the spot', $content);
    }

    #[DataProvider('engines')]
    public function testAnOrdinaryPatternStillMatchesInsideTheRoot(bool $rg): void
    {
        $result = (new Grep($this->root, rgAvailable: $rg))->execute([
            'pattern' => 'needle',
            'path' => '.',
            'description' => 'ordinary pattern',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString($this->root . '/inner.txt:1:a needle in the root', $result->content());
    }

    /**
     * The `-r` symlink hatch stays as documented: a link MET during the walk
     * is not followed, so a plain search for the secret finds nothing. rg
     * encodes the same doctrine as its default (measured: no walk-time descent
     * into `innocent_link`), and the tool never passes `--follow`.
     */
    #[DataProvider('engines')]
    public function testAPlainSearchDoesNotFollowAnOutOfRootLink(bool $rg): void
    {
        $content = (new Grep($this->root, rgAvailable: $rg))->execute([
            'pattern' => 'SECRET',
            'path' => '.',
            'description' => 'plain secret search',
        ])->content();

        $this->assertStringNotContainsString('hunter2', $content);
    }

    /**
     * C-TOOL-1.b — the operator's rg config file must not widen the walk.
     * RIPGREP_CONFIG_PATH makes rg prepend that file's lines to argv, so a
     * lone `--follow` line reopens the `-R` hole out of band: the walk exits
     * the jail through this fixture's `innocent_link`, and because the hit
     * path the walk reports is still jail-prefixed, hitPath() cannot tell the
     * smuggled descent from a contained one (the leak was reproduced through
     * this exact spawn path before the fix). The command line carries
     * `--no-config`, and the child's environment blanks the variable — belt
     * and braces, so neither the flag channel nor the env channel reaches rg.
     *
     * Early-returns (never skips) on rg-less hosts, the differential legs'
     * convention: there is no binary here to configure.
     */
    public function testRipgrepConfigFileCannotWidenTheWalk(): void
    {
        if (!Bootstrap::capabilityPresent('rg')) {
            return;
        }

        $rc = $this->base . '/ripgreprc';
        file_put_contents($rc, "--follow\n");

        $previous = getenv('RIPGREP_CONFIG_PATH');
        putenv('RIPGREP_CONFIG_PATH=' . $rc);
        try {
            $content = (new Grep($this->root, rgAvailable: true))->execute([
                'pattern' => 'SECRET',
                'path' => '.',
                'description' => 'config-injected follow',
            ])->content();
        } finally {
            // Restore on the failure path too: a leaked hostile rc would
            // poison every later rg child in the process.
            if ($previous === false) {
                putenv('RIPGREP_CONFIG_PATH');
            } else {
                putenv('RIPGREP_CONFIG_PATH=' . $previous);
            }
        }

        $this->assertStringNotContainsString('hunter2', $content);
        $this->assertStringNotContainsString('innocent_link', $content);
        $this->assertStringNotContainsString('creds.txt', $content);
    }

    /**
     * The argv half of the fix, pinned without a binary: `--no-config` rides
     * the command as the FIRST rg flag — before any flag the config could
     * rewrite — and the pin holds on rg-less hosts because buildCommand is
     * pure string work.
     */
    public function testTheBuiltRgCommandCarriesNoConfigAsItsFirstFlag(): void
    {
        $cmd = self::builtRgCommandLine();

        $this->assertIsString($cmd);
        $this->assertStringStartsWith('rg --no-config ', $cmd);
    }

    /**
     * The env half of the fix, pinned without a spawn. The belt line —
     * ['RIPGREP_CONFIG_PATH' => ''] as runCaptured()'s scrubbedEnv override —
     * is invisible to every functional test in this file: the e2e repro stays
     * green without it (--no-config alone defeats the --follow rc), and the
     * argv pin never reads the environment. This structural source-slice
     * assertion is therefore the ONLY thing distinguishing the shipped double
     * guard from a single-flag line: it reads the runCaptured call site out of
     * Grep.php and requires the override to ride INSIDE the call. Deleting the
     * override, moving it outside the call, or growing a second runCaptured
     * call site (which would make "the call site" ambiguous) each redden it.
     */
    public function testTheRgSpawnCarriesTheEnvBlankingOverrideIntoRunCaptured(): void
    {
        $path = realpath(__DIR__ . '/../../../src/Tools/BuiltIn/Grep.php');
        $this->assertIsString($path, 'the structural pin needs the Grep.php source');

        $source = file_get_contents($path);
        $this->assertIsString($source);

        $this->assertSame(
            1,
            substr_count($source, '$this->runCaptured('),
            'the spawn is a single call site; a second one must be adjudicated into this pin'
        );

        $open = strpos($source, '$this->runCaptured(');
        $this->assertIsInt($open);

        $end = strpos($source, ");\n", $open);
        $this->assertIsInt($end, 'the runCaptured call must terminate with ); at a line end');

        $callSite = substr($source, $open, $end - $open + 2);
        $this->assertStringContainsString("'RIPGREP_CONFIG_PATH' => ''", $callSite);
    }

    /**
     * Reflection onto the private buildCommand() with a throwaway rooted
     * instance whose own root the method never reads — the same no-spawn
     * shape GrepEngineDifferentialTest uses for its argv legs.
     */
    private static function builtRgCommandLine(): ?string
    {
        $tool = new Grep(root: '/', rgAvailable: true);
        $method = new ReflectionMethod($tool, 'buildCommand');

        /** @var ?string $cmd */
        $cmd = $method->invoke($tool, IgnoreRules::new('/'), '*', 'needle', '/tmp/opencode/ctool1b-inner.txt');

        return $cmd;
    }

    /**
     * `--include=<glob>` is one argv token, so an option-shaped glob is the
     * option's value: it filters filenames and enables nothing. rg's
     * `--glob=<value>` is the same single-token form (measured, including a
     * value that itself begins with `-`).
     */
    #[DataProvider('optionShapedIncludesUnderBothEngines')]
    public function testAnOptionShapedIncludeIsOnlyAFilenameGlob(string $include, bool $rg): void
    {
        $content = (new Grep($this->root, rgAvailable: $rg))->execute([
            'pattern' => 'SECRET',
            'path' => '.',
            'include' => $include,
            'description' => 'option-shaped include',
        ])->content();

        $this->assertStringNotContainsString('hunter2', $content);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function optionShapedIncludes(): array
    {
        return [
            'bare -R' => ['-R'],
            'long dereference flag' => ['--dereference-recursive'],
            'include smuggling a second option' => ['*.txt --dereference-recursive'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function optionShapedIncludesUnderBothEngines(): array
    {
        return self::crossProduct(self::optionShapedIncludes());
    }

    /**
     * The engines this host can actually run: grep always (it is the fallback),
     * rg only when the boot probe finds it, so an rg-less container gets the
     * same dataset it got before SHL-4 rather than a farm of red legs.
     *
     * @return array<string, array{0: bool}>
     */
    public static function engines(): array
    {
        $cases = [];
        foreach (self::hostEngines() as $rg) {
            $cases[$rg ? 'rg' : 'grep'] = [$rg];
        }

        return $cases;
    }

    /**
     * Cross a single-string-argument provider with {@see engines()}, keeping a
     * `[grep]`/`[rg]` suffix in the case label so a red line names its engine.
     *
     * @param array<string, array{0: string}> $cases
     * @return array<string, array{0: string, 1: bool}>
     */
    private static function crossProduct(array $cases): array
    {
        $out = [];
        foreach ($cases as $label => [$value]) {
            foreach (self::hostEngines() as $rg) {
                $out[$label . ($rg ? ' [rg]' : ' [grep]')] = [$value, $rg];
            }
        }

        return $out;
    }

    /**
     * @return list<bool>
     */
    private static function hostEngines(): array
    {
        return Bootstrap::capabilityPresent('rg') ? [false, true] : [false];
    }

    /**
     * The unrooted instance (tests, embedders) passes `path` through as given,
     * so a directory literally named `-R` used to be read by grep as the `-R`
     * flag — leaving no file operand, so grep dereference-walked `.` instead.
     * `--` makes it the directory it is, on both engines.
     */
    #[DataProvider('engines')]
    public function testADashNamedDirectoryIsSearchedAsADirectory(bool $rg): void
    {
        mkdir($this->root . '/-R');
        file_put_contents($this->root . '/-R/inside.txt', "SECRET-free needle\n");

        $grep = new Grep(rgAvailable: $rg);

        $secret = $grep->execute(['pattern' => 'SECRET', 'path' => '-R', 'description' => 'x'])->content();
        $this->assertStringNotContainsString('hunter2', $secret);

        $needle = $grep->execute(['pattern' => 'needle', 'path' => '-R', 'description' => 'x'])->content();
        $this->assertStringContainsString('-R/inside.txt:1:SECRET-free needle', $needle);
    }

    /**
     * A non-string argument is a malformed call answered as an error, not a
     * TypeError out of escapeshellarg().
     *
     * @param array<string, mixed> $args
     */
    #[DataProvider('nonStringArguments')]
    public function testANonStringArgumentIsAnErrorResultNotACrash(array $args, string $named): void
    {
        $result = (new Grep($this->root))->execute($args + ['description' => 'x']);

        $this->assertTrue($result->isError());
        $this->assertSame("Error: $named must be a string", $result->content());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function nonStringArguments(): array
    {
        return [
            'pattern array' => [['pattern' => ['-R'], 'path' => '.'], 'pattern'],
            'path number' => [['pattern' => 'x', 'path' => 7], 'path'],
            'include array' => [['pattern' => 'x', 'path' => '.', 'include' => ['-R']], 'include'],
        ];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            // is_link() FIRST: is_dir() follows a symlink, so recursing would
            // empty the link's target instead of removing the link.
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            self::removeTree($path);
        }

        rmdir($dir);
    }
}
