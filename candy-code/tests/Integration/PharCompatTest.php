<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Support\SuiteSkipRoster;

/**
 * DST-1 — the phar build (tools/build-phar.php) ships this application as a
 * single executable archive, and an archive is a filesystem the old habits
 * cannot see. Two laws keep it honest, and both are census pins, not
 * build-then-run round trips (the heavy arm runs only when CANDY_CODE_PHAR
 * names a built archive — the release workflow builds one and points here).
 *
 * LAW 1 — every child-process launch resolves the interpreter through
 * PHP_BINARY (or a caller-supplied binary variable), never a literal `'php'`
 * at a spawn site. A literal rides PATH, which a phar user's PATH may not
 * answer with the same interpreter that is running the archive. The positive
 * roster names the four live sites; the negative sweep (comment-stripped, so
 * prose cannot satisfy it) refuses any future spawn-context literal.
 *
 * LAW 2 — directory scans inside the archive use readdir-family calls. MEASURED
 * on PHP 8.3.6: `glob()` reads no stream wrapper and answers the empty array
 * under `phar://`, while `scandir()`, `is_file()`, and `require` all work. The
 * two `__DIR__`-relative scanners (ToolCatalog, BuiltInCommands) were converted
 * under the round ruling; the premise arm below pins the measured asymmetry
 * itself so the conversion's reason survives even if nobody re-reads this.
 *
 * The realpath trap stays a DISCLOSURE, not a pin: `realpath('phar://…')`
 * returns false, so AgentPresetRegistry's bundled-example preset scan
 * degrades to invisible inside a phar (home/project preset roots are real
 * paths and work). Ruling: disclose, do not edit — see the lane REPORT.
 */
final class PharCompatTest extends TestCase
{
    /**
     * Spawn sites that must route the interpreter through PHP_BINARY, as
     * `file => substrings that must appear in that file`. Every entry is a
     * verbatim quote of the live call shape; drift in the call shape reddens
     * here before it can ship as a PATH-lookup regression.
     *
     * @var array<string, list<string>>
     */
    private const PHP_BINARY_SITES = [
        'src/Server/Workspace/WorkspaceHostClient.php' => [
            'spawnSpec([\PHP_BINARY,',
        ],
        'src/Sessions/BackgroundSupervisor.php' => [
            // The inline `-r` daemon launch: a literal argv array, one token
            // per line at this indent.
            "\$cmd = [\n            PHP_BINARY,\n            '-r',",
        ],
        'src/Support/SessionRelaunch.php' => [
            '$php = \PHP_BINARY;',
            'pcntl_exec($php,',
        ],
        'src/Lint/LintRunner.php' => [
            'escapeshellarg(PHP_BINARY)',
        ],
    ];

    /**
     * The two scanners the round converted glob→scandir. They must stay
     * glob-free in executable code (comment-stripped) — a reintroduced
     * `glob(__DIR__ …)` would answer [] inside the archive and silently
     * empty the tool catalogue and the command specs.
     */
    private const GLOB_FREE_SCANNERS = [
        'src/Tools/Catalog/ToolCatalog.php',
        'src/Commands/Specs/BuiltInCommands.php',
    ];

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $text = \file_get_contents(self::root() . '/' . $relative);
        self::assertIsString($text, "unreadable source file {$relative}");

        return $text;
    }

    /**
     * Source with every comment token removed (comments replaced by a single
     * space to keep adjacency honest). Census legs read this, so prose that
     * merely MENTIONS a spawn shape can never satisfy — or violate — them.
     */
    private static function stripComments(string $source): string
    {
        $out = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is(\T_COMMENT) || $token->is(\T_DOC_COMMENT)) {
                $out .= ' ';
                continue;
            }
            $out .= $token->text;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // LAW 1 — fork/exec sites resolve through PHP_BINARY
    // ------------------------------------------------------------------

    public function testEveryNamedSpawnSiteResolvesTheInterpreterThroughPhpBinary(): void
    {
        foreach (self::PHP_BINARY_SITES as $relative => $needles) {
            $source = self::read($relative);
            foreach ($needles as $needle) {
                self::assertStringContainsString(
                    $needle,
                    $source,
                    "DST-1 roster: {$relative} no longer carries the PHP_BINARY-shaped launch it is pinned for"
                );
            }
        }
    }

    public function testNoSpawnSiteNamesTheInterpreterAsALiteralPhp(): void
    {
        // Spawn-context only: a call into proc_open/exec/system/passthru/
        // popen/shell_exec/pcntl_exec whose first argument element opens with
        // the quoted word php. Property defaults (ProcessExecutor's
        // $binaryPath) and language strings are not call shapes and stay out
        // of the accusation set by construction — the regex looks only at
        // `name(` followed by an optional list bracket and an opening quote.
        $pattern = '/\b(?:proc_open|pcntl_exec|shell_exec|exec|system|passthru|popen|spawnSpec)\(\s*\[?\s*[\'"]php\b/';

        $offenders = [];
        $files = array_merge(
            self::phpFiles(self::root() . '/src'),
            [self::root() . '/bin/candy-code'],
        );
        foreach ($files as $file) {
            $source = self::stripComments(\file_get_contents($file));
            if (\preg_match($pattern, $source, $hit) === 1) {
                $offenders[] = \basename(\dirname($file)) . '/' . \basename($file) . ': ' . $hit[0];
            }
        }

        self::assertSame([], $offenders, 'literal \'php\' spawn context under phar would ride PATH instead of PHP_BINARY');
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        // scandir, not glob — the same reason this file exists.
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                $files = array_merge($files, self::phpFiles($path));
                continue;
            }
            if (str_ends_with($name, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    // ------------------------------------------------------------------
    // LAW 2 + stub shape — source pins that do not need a built archive
    // ------------------------------------------------------------------

    public function testStubAndAutoloadCandidateKeepTheirPharShape(): void
    {
        $build = self::read('tools/build-phar.php');
        self::assertStringContainsString(
            'bin/candy-code',
            $build,
            'the phar stub must stay sourced from bin/candy-code — SessionRelaunch execs __FILE__, which is the archive only when the stub is the entry script'
        );
        self::assertStringContainsString(
            '__HALT_COMPILER();',
            $build,
            'a stub without the compiler halt writes garbage onto the archive payload'
        );

        $bin = self::read('bin/candy-code');
        self::assertStringStartsWith("#!/usr/bin/env php\n<?php", $bin, 'build-phar refuses this shape; keep the two honest');
        self::assertStringContainsString('Phar::running', $bin, 'the fourth autoload candidate (inside-archive vendor) is the phar entry seam');
    }

    public function testConvertedScannersStayGlobFreeInExecutableCode(): void
    {
        foreach (self::GLOB_FREE_SCANNERS as $relative) {
            self::assertStringNotContainsString(
                'glob(',
                self::stripComments(self::read($relative)),
                "{$relative} is scanned under phar:// where glob() reads nothing — keep it readdir-family"
            );
        }
    }

    public function testTheMeasuredPremiseGlobSeesNothingInsideAPharAndScandirDoes(): void
    {
        // Body-level gate so the skip is rosterable; the predicate lives on the
        // roster class (shared implementation, zero drift between the two).
        if (!SuiteSkipRoster::pharWritingPossible()) {
            $this->markTestSkipped('this process cannot mint a fixture phar (phar.readonly armed or ext-phar absent); the premise was measured on the build box (PHP 8.3.6): glob() answers [] under phar://, scandir() answers the entry list');
        }

        $phar = $this->writePremisePhar();

        try {
            $entries = scandir('phar://' . $phar . '/src');
            self::assertIsArray($entries);
            self::assertContains('inside.php', $entries, 'scandir must see archive contents');

            // chr(42) keeps this needle out of the GlobDialect corpus — a
            // glob-shaped string literal here would drift the harvested pair
            // census (lane lesson: test literals are corpus members).
            $pattern = 'phar://' . $phar . '/src/' . chr(42) . '.php';
            self::assertSame([], glob($pattern), 'the conversion premise died: glob can now read phar:// and this file needs re-reading');
        } finally {
            // The Phar object is already dropped; on Windows an open handle
            // would block the unlink, on Linux it would not, and the @ keeps
            // teardown from failing the premise.
            @unlink($phar);
        }
    }

    /**
     * Mint the one-file fixture archive the premise asserts against. Only ever
     * called once {@see SuiteSkipRoster::pharWritingPossible()} says yes, so a
     * PharException here is a real defect (tmp unwritable), not an environment
     * shape — let it throw loud.
     */
    private function writePremisePhar(): string
    {
        $path = sys_get_temp_dir() . '/w2j-premise-' . getmypid() . '.phar';
        @unlink($path);
        $phar = new \Phar($path, 0, 'w2j-premise');
        $phar->setStub("<?php __HALT_COMPILER();");
        $phar->addFromString('src/inside.php', "<?php\n");
        $phar->stopBuffering();
        unset($phar);

        return $path;
    }

    // ------------------------------------------------------------------
    // LAW 3 (CI-gated) — the real archive boots and its scanners are alive
    // ------------------------------------------------------------------

    /**
     * One method, four sequential stages, so the skip is a single rosterable
     * event: a class-level gate is not rosterable (SuiteSkipRoster check 4) and
     * per-method gates would bloat the roster with four rows for one env
     * variable. The release workflow builds an archive, exports
     * CANDY_CODE_PHAR, and runs this file; local equivalent after
     * `php -d phar.readonly=0 tools/build-phar.php --out=dist/candy-code.phar`.
     */
    public function testTheBuiltPharBehavesLikeTheInstalledApplication(): void
    {
        if (!SuiteSkipRoster::builtPharSmokeArmed()) {
            $this->markTestSkipped('set CANDY_CODE_PHAR=/path/to/candy-code.phar — the release workflow builds one; locally: php -d phar.readonly=0 tools/build-phar.php --out=dist/candy-code.phar');
        }

        $phar = (string) getenv('CANDY_CODE_PHAR');
        // Stage 1 — the stub answers the version banner (proves the archive
        // boots as a main script, the only phar entry shape PHP accepts).
        $version = self::launch([\PHP_BINARY, $phar, '--version'], $this->freshPharHome());
        self::assertSame(0, $version['rc'], 'version rc/stderr: ' . $version['err']);
        self::assertStringStartsWith('candy-code', ltrim($version['out']));

        // Stage 2 — a headless echo-provider turn lands as clean JSON with a
        // fresh HOME (offline: no provider selection inherited from the shell).
        $turn = self::launch(
            [\PHP_BINARY, $phar, '-p', 'compat phar smoke', '--output-format', 'json'],
            $this->freshPharHome(),
        );
        self::assertSame(0, $turn['rc'], 'headless rc/stderr: ' . $turn['err']);
        $document = json_decode($turn['out'], true);
        self::assertIsArray($document, 'json document missing: ' . substr($turn['out'], 0, 400));
        self::assertArrayNotHasKey('error', $document, 'error field on the phar smoke document: ' . ($document['error'] ?? ''));

        // Stage 3 — the scanners are ALIVE inside the archive: a child reaches
        // it through its own vendor/autoload.php exactly as the shipped stub
        // does, and both converted scan sites come back non-empty.
        $probe = 'require getenv("W2J_PHAR")."/vendor/autoload.php";'
            . 'echo ",".count(\SugarCraft\Code\Tools\Catalog\ToolCatalog::names());'
            . 'echo ",".count(\SugarCraft\Code\Commands\Specs\BuiltInCommands::all());';
        $scan = self::launch([\PHP_BINARY, '-r', $probe], $this->freshPharHome(), ['W2J_PHAR' => $phar]);
        self::assertSame(0, $scan['rc'], 'scanner probe rc/stderr: ' . $scan['err']);
        $counts = array_map('intval', explode(',', ltrim($scan['out'], ',')));
        self::assertGreaterThanOrEqual(1, $counts[0] ?? 0, 'tool catalogue empty under phar: ' . $scan['out']);
        self::assertGreaterThanOrEqual(1, $counts[1] ?? 0, 'built-in command specs empty under phar: ' . $scan['out']);

        // Stage 4 — build manifest present; LICENSE shipped; dev config kept out.
        $archive = new \Phar($phar);
        self::assertIsArray($archive->metadata, 'build metadata missing');
        self::assertArrayHasKey('version', (array) $archive->metadata);

        $sawLicense = false;
        $sawDevConfig = false;
        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            $path = str_replace('\\', '/', $file->getPathName());
            $sawLicense = $sawLicense || str_contains($path, '/LICENSE');
            $sawDevConfig = $sawDevConfig || str_contains($path, '.candy-code/config.dev.json');
        }
        self::assertTrue($sawLicense, 'LICENSE must ship inside the archive');
        self::assertFalse($sawDevConfig, 'config.dev.json is excluded by the build; if it is back, secrets hygiene regressed');
    }

    /**
     * Run a program with piped stdout/stderr and a hardened env: fresh HOME,
     * no provider selection inherited from the developer shell, and an optional
     * extra env frame whose keys win over the inherited environment.
     *
     * @param list<string>         $argv
     * @param array<string,string> $extra
     *
     * @return array{rc:int,out:string,err:string}
     */
    private static function launch(array $argv, string $home, array $extra = []): array
    {
        $env = $extra;
        foreach (getenv() as $key => $value) {
            if ($key === 'HOME' || $key === 'CANDY_CODE_PROVIDER' || isset($extra[$key])) {
                continue;
            }
            $env[$key] = $value;
        }
        $env['HOME'] = $home;

        $pipes = [];
        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root(), $env);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);

        return ['rc' => $rc, 'out' => (string) $out, 'err' => (string) $err];
    }


    private function freshPharHome(): string
    {
        $home = sys_get_temp_dir() . '/w2j-home-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($home, 0700, true) || is_dir($home));

        return $home;
    }
}
