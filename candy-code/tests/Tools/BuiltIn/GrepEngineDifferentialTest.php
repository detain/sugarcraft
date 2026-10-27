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
 * SHL-4 — the two Grep engines must answer identically, and the answer to
 * "identically" is measured here rather than argued in a docblock.
 *
 * One fixture tree, every case run under BOTH `grep -rnH` (the fallback every
 * host has; the -H is an SHL-4 parity port — see `explicit file operand`) and
 * `rg` (the engine a capable host gets), asserting the two
 * results agree. The rg legs join the datasets only when the boot probe finds
 * rg — the probe the production wiring itself consults — so an rg-less
 * container (the CI image today) runs the structural half and simply never
 * grows the behavioural legs; nothing here markTestSkippeds mid-suite.
 *
 * WHAT "identically" means per output line: both engines print
 * `absolute/path:line:text` for every text hit (rg needs --with-filename for
 * a bare FILE operand — measured: without it rg answers `1:SECRET=3` and the
 * post-filter loses attribution), so the hit sets are compared as SORTED line
 * sets: grep walks depth-first in readdir order, rg walks in whatever order
 * its parallel matcher finishes, and the tool contract never promised an
 * order. The note lines the pipeline appends downstream (skipped/gitignored)
 * are regenerated engine-agnostically from the capture and the same ruleset,
 * so they ride inside the compared content rather than being excused from it.
 *
 * THE ONE DECLARED NON-PARITY: binary files, pinned rather than smoothed.
 * grep's walk emits `grep: <path>: binary file matches` on stdout (the
 * diagnostic form, which `Grep::hitPath()` does not recognise and the
 * post-filter therefore KEEPS as unattributable; its exact-wording regex
 * `^Binary file (.*) matches$` stays load-bearing for the older/historical
 * shape). rg's walk SILENTLY SKIPS binary files by default (measured: exit 1,
 * nothing on either stream) — the argv carries `--binary` to restore the
 * verdict (`<path>: binary file matches (found "\0" byte around offset N)`,
 * attributed through the path-prefix colon branch, emitted as literal text
 * with no NUL in the payload). The two verdicts name the same file but are
 * not the same bytes, so the binary fixture
 * rides the equality sweep's exclusion list and gets its own leg below,
 * pinning each engine's real wording — an honest delta, not a test bent until
 * equality passes.
 *
 * The load-bearing GNU specifics whose port this file measures: the F-J2
 * secret screen (whose explicit-operand gap on rg the tool-side door closes —
 * `secret named as operand` and `git dir named as operand` are exactly the
 * cases rg would have leaked), the include-before-exclude ordering trap (grep
 * decides a double-matching file by its FIRST glob option, rg by its LAST —
 * the `secret names via include` leg pins that the shared argv order keeps
 * both engines closed), and the -r-not--R symlink doctrine (`named operand
 * link` is entered by both engines, `link met during the walk` by neither).
 */
final class GrepEngineDifferentialTest extends TestCase
{
    private string $root = '';
    private string $outside = '';
    private string $previousCwd = '';

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/crush-grep-diff-' . bin2hex(random_bytes(6));
        $this->root = $base . '/root';
        $this->outside = $base . '/outside';
        mkdir($this->root . '/sub', 0o777, true);
        mkdir($this->root . '/vendor/v', 0o777, true);
        mkdir($this->root . '/node_modules', 0o777, true);
        mkdir($this->root . '/.git', 0o777, true);
        mkdir($this->outside, 0o777, true);

        file_put_contents($this->root . '/.gitignore', "ignored.dat\n");
        // A rg-only ignore file: rg honors .ignore even outside a git repo
        // (measured), grep has never heard of it. --no-ignore is what keeps
        // skipme.dat a hit on BOTH engines; if that flag ever regresses, the
        // `whole tree` leg goes red on exactly this pair of paths.
        file_put_contents($this->root . '/.ignore', "skipme.dat\n");
        file_put_contents($this->root . '/a.php', "needle alpha\n");
        file_put_contents($this->root . '/b.txt', "needle beta\n");
        file_put_contents($this->root . '/sub/c.php', "needle gamma\nneedle second\n");
        file_put_contents($this->root . '/.hidden.txt', "needle hidden\n");
        file_put_contents($this->root . '/.env', "needle secret=DB_PASSWORD=s3cr3t\n");
        file_put_contents($this->root . '/.env.example', "needle template\n");
        file_put_contents($this->root . '/.git/config', "needle gitconf\n");
        file_put_contents($this->root . '/vendor/v/v.txt', "needle vendored\n");
        file_put_contents($this->root . '/node_modules/n.txt', "needle nm\n");
        file_put_contents($this->root . '/ignored.dat', "needle gitignored\n");
        file_put_contents($this->root . '/skipme.dat', "needle rg-ignored-file\n");
        file_put_contents($this->root . '/id_rsa', "needle PRIVATE KEY\n");
        file_put_contents($this->root . '/id_rsa.pub', "needle public half\n");
        file_put_contents($this->outside . '/outside.txt', "needle outside\n");
        // A NUL-bearing file whose payload deliberately does NOT contain the
        // equality sweep's pattern: an unmatched binary file emits no line on
        // either engine, so it cannot false-red the parity legs. Its own leg
        // below hits it with its own token and pins each engine's wording —
        // the declared binary-format delta (see class docblock). The token
        // sits before the first NUL so the DIRECT-operand binary verdict is
        // unambiguous on both engines; the walk verdicts (grep's diagnostic,
        // rg's --binary line) arrive regardless.
        file_put_contents($this->root . '/bin.dat', "binmatch\x00trailer\x00\n");
        symlink('../outside', $this->root . '/link_out');
        symlink('.env', $this->root . '/notes.txt');

        $this->previousCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== '') {
            chdir($this->previousCwd);
        }
        exec('rm -rf ' . escapeshellarg(\dirname($this->root)));
    }

    #[DataProvider('parityCases')]
    public function testBothEnginesAnswerIdentically(array $args): void
    {
        $viaGrep = $this->search($args, false);
        $viaRg = $this->search($args, true);

        $this->assertSame(
            self::canonical($viaGrep),
            self::canonical($viaRg),
            'engines disagree about ' . json_encode($args)
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function parityCases(): array
    {
        if (!Bootstrap::capabilityPresent('rg')) {
            // Without rg there is no second engine to compare against; the
            // structural legs below still run and still hold the host's
            // fallback behaviour pinned.
            return [];
        }

        return [
            'whole tree' => [['pattern' => 'needle', 'path' => '.']],
            'php include' => [['pattern' => 'needle', 'path' => '.', 'include' => '*.php']],
            'ignored included too' => [['pattern' => 'needle', 'path' => '.', 'include_ignored' => true]],
            'explicit file operand' => [['pattern' => 'needle', 'path' => 'sub/c.php']],
            // The F-J2 cases: on rg these would print the secret from the
            // operand unless the tool-side door answers first.
            'secret named as operand' => [['pattern' => 'needle', 'path' => '.env', 'include_ignored' => true]],
            'key named as operand' => [['pattern' => 'PRIVATE KEY', 'path' => 'id_rsa']],
            'symlink to secret as operand' => [['pattern' => 'needle', 'path' => 'notes.txt']],
            'git dir named as operand' => [['pattern' => 'needle', 'path' => '.git', 'include_ignored' => true]],
            // The ordering trap: the include glob overlaps the secret family.
            'secret names via include' => [['pattern' => 'needle', 'path' => '.', 'include' => '.env*', 'include_ignored' => true]],
            // -r doctrine: naming an excluded directory asks for it on both.
            'vendor named as operand' => [['pattern' => 'needle', 'path' => 'vendor']],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('parityCases')]
    public function testIncludeOverlappingSecretKeepsBothEnginesOnTheSaferSide(array $args): void
    {
        foreach ([false, true] as $rg) {
            $content = $this->search($args, $rg)['content'];
            $this->assertStringNotContainsString('s3cr3t', $content);
            $this->assertStringNotContainsString('gitconf', $content);
        }
    }

    /**
     * The named-operand symlink entry — grep -r and rg agree that a link the
     * model NAMES is a request to enter it (measured on rg 14.1.0), while a
     * link merely met by the walk stays un-followed on both (`whole tree`
     * compares that half: no `outside.txt` line reaches either engine, or the
     * sets would not be equal). The rooted instance jails the operand instead
     * (PathJail resolves the link and finds it outside the root), so this leg
     * runs the UNROOTED instance with the process cwd pinned to the fixture —
     * the same shape the unrooted doctrine test in `GrepOptionInjectionTest`
     * uses, and the reason the answer must be equal: the tool fell out of the
     * jail through an entry point the model is allowed to use.
     */
    public function testANamedOutOfRootLinkIsEnteredByBothEngines(): void
    {
        chdir($this->root);
        $args = ['pattern' => 'needle', 'path' => 'link_out', 'unrooted' => true];

        $viaGrep = $this->search($args, false);
        $this->assertStringContainsString('link_out/outside.txt:1:needle outside', $viaGrep['content']);

        if (!Bootstrap::capabilityPresent('rg')) {
            return;
        }
        $viaRg = $this->search($args, true);

        $this->assertSame(self::canonical($viaGrep), self::canonical($viaRg));
        $this->assertStringContainsString('link_out/outside.txt:1:needle outside', $viaRg['content']);
    }

    /**
     * The declared binary delta, pinned rather than smoothed: rg attributes
     * through hitPath()'s colon branch (and needs --binary in argv to answer
     * during a walk at all — measured); grep 3.11's walk emits its own
     * `grep: <path>: binary file matches` diagnostic, kept verbatim by the
     * unattributable-line rule (measured: on stdout, exit 0). Neither engine
     * may smuggle the NUL-bearing line itself into the model's content. The
     * fixture's token sits before the first NUL only to keep the DIRECT-operand
     * shape honest for both engines; the walk verdicts arrive either way.
     */
    public function testBinaryHitsStayAttributableOnBothEngines(): void
    {
        $viaGrep = $this->search(['pattern' => 'binmatch', 'path' => '.'], false);
        $this->assertStringNotContainsString("\x00", $viaGrep['content']);
        // Whatever wording grep 3.11 chose for the binary verdict, it must
        // still name the file (measured: the walk emits the `grep: <path>:`
        // diagnostic form on stdout; the keep-unattributable rule rides it
        // through, and the answer stays attributable either way).
        $this->assertStringContainsString('bin.dat', $viaGrep['content']);

        if (!Bootstrap::capabilityPresent('rg')) {
            return;
        }
        $viaRg = $this->search(['pattern' => 'binmatch', 'path' => '.'], true);
        $this->assertStringNotContainsString("\x00", $viaRg['content']);
        $this->assertStringContainsString($this->root . '/bin.dat: binary file matches', $viaRg['content']);
    }

    /**
     * The argv contract itself, engine by engine — no process spawn needed, so
     * these legs hold even on rg-less hosts: a build machine pinning the
     * command line it WOULD run must not itself depend on the binary.
     */
    public function testTheGrepCommandKeepsItsHistoricShape(): void
    {
        $cmd = self::buildCommandFor(false, $this->root . '/a.php');

        $this->assertIsString($cmd);
        $this->assertStringStartsWith('grep -rnH', $cmd);
        $this->assertStringContainsString(' --exclude-dir=' . escapeshellarg('vendor'), $cmd);
        $this->assertStringContainsString(' --exclude=.env ', $cmd);
        $this->assertStringContainsString(' -e ', $cmd);
        $this->assertStringEndsWith(' -- ' . escapeshellarg($this->root . '/a.php'), $cmd);
    }

    public function testTheRgCommandCarriesTheParityFlagsAndTheGlobSet(): void
    {
        $cmd = self::buildCommandFor(true, $this->root . '/a.php');

        $this->assertIsString($cmd);
        $this->assertStringStartsWith(
            'rg --no-config --no-heading --line-number --with-filename --color never --binary --hidden --no-ignore',
            $cmd
        );
        // --with-filename is load-bearing: without it a bare FILE operand
        // answers `1:needle alpha` and the path vanishes from the contract.
        $this->assertStringContainsString(' --glob=' . escapeshellarg('!vendor'), $cmd);
        $this->assertStringContainsString(' --glob=' . escapeshellarg('!' . '.env'), $cmd);
        $this->assertStringContainsString(' -e ', $cmd);
        $this->assertStringEndsWith(' -- ' . escapeshellarg($this->root . '/a.php'), $cmd);
        // The -R hole with rg's spelling: never followed during the walk.
        $this->assertStringNotContainsString('--follow', $cmd);
        $this->assertStringNotContainsString(' -L ', $cmd);
    }

    public function testTheDoorScreensSecretOperandsFromRgAndOnlyRg(): void
    {
        // grep screens these itself, on the command line, so its command
        // builds even for a named secret — the door must not fire twice.
        $this->assertIsString(self::buildCommandFor(false, $this->root . '/.env'));
        // rg cannot screen an explicit operand (measured): the door answers
        // null and the spawn never happens.
        $this->assertNull(self::buildCommandFor(true, $this->root . '/.env'));
        $this->assertNull(self::buildCommandFor(true, $this->root . '/id_rsa'));
        $this->assertNull(self::buildCommandFor(true, $this->root . '/.git'));
        // Ordinary operands still build.
        $this->assertIsString(self::buildCommandFor(true, $this->root . '/a.php'));
        $this->assertIsString(self::buildCommandFor(true, $this->root . '/sub'));
    }

    /**
     * @param array<string, mixed> $args
     * @return array{content: string, isError: bool}
     */
    private function search(array $args, bool $rg): array
    {
        $unrooted = ($args['unrooted'] ?? null) === true;
        unset($args['unrooted']);

        $tool = $unrooted === true
            ? new Grep(rgAvailable: $rg)
            : new Grep($this->root, rgAvailable: $rg);

        $result = $tool->execute($args + ['description' => 'differential']);

        return ['content' => $result->content(), 'isError' => $result->isError()];
    }

    /**
     * Compare hit sets, not walk order: rg parallelises, grep does not, and
     * only the SET is the tool's contract.
     *
     * @param array{content: string, isError: bool} $result
     */
    private static function canonical(array $result): string
    {
        $lines = explode("\n", $result['content']);
        sort($lines);

        return ($result['isError'] ? 'ERR|' : 'OK|') . implode("\n", $lines);
    }

    /**
     * Reflection onto the private buildCommand() without a spawn: (rules,
     * include, pattern, path) over a throwaway rooted instance whose own root
     * the method never reads.
     */
    private static function buildCommandFor(bool $rg, string $path, string $include = '*'): ?string
    {
        $tool = new Grep(root: '/', rgAvailable: $rg);
        $method = new ReflectionMethod($tool, 'buildCommand');

        /** @var ?string $cmd */
        $cmd = $method->invoke($tool, IgnoreRules::new('/'), $include, 'needle', $path);

        return $cmd;
    }
}
