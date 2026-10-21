<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Support\Version;

/**
 * DST-1 — the phar build (tools/build-phar.php) ships this application as a
 * single executable archive, and an archive is a filesystem the old habits
 * cannot see. Three laws keep it honest; the first two are census pins, not
 * build-then-run round trips (the heavy arm runs only when CANDY_TOP_PHAR
 * names a built archive — the release workflow builds one and points here).
 *
 * Port of candy-code/tests/Integration/PharCompatTest.php with candy-top's
 * measured surface. Where candy-code names four live PHP_BINARY spawn sites
 * and two converted glob scanners, candy-top has NEITHER: measured over
 * src/** + bin there is no child-process launch that names an interpreter at
 * all (the app spawns `watch`/`sensors`-style system binaries through
 * escapeshellarg command strings, never PHP), and the only `__DIR__`-relative
 * data scanner is ThemeRegistry (themes/), which was born scandir-family; the
 * logo corpus walks through sugar-animation's ArtLibrary, shipped inside the
 * vendor root of the archive. The census legs stay — an EMPTY positive roster
 * plus the negative sweep is what keeps a future spawn site honest.
 *
 * LAW 1 — every child-process launch resolves the interpreter through
 * PHP_BINARY (or a caller-supplied binary variable), never a literal `'php'`
 * at a spawn site. A literal rides PATH, which a phar user's PATH may not
 * answer with the same interpreter that is running the archive. The negative
 * sweep (comment-stripped, so prose cannot satisfy it) refuses any future
 * spawn-context literal.
 *
 * LAW 2 — directory scans inside the archive use readdir-family calls. MEASURED
 * on PHP 8.3.6: `glob()` reads no stream wrapper and answers the empty array
 * under `phar://`, while `scandir()`, `is_file()`, and `require` all work.
 * The premise arm below pins the measured asymmetry itself so the law's
 * reason survives even if nobody re-reads this. Also measured FOR THIS PORT
 * (dotdot fixture, PHP 8.3.6): `'..'` segments inside a `phar://` URI RESOLVE
 * for is_dir/is_file/scandir — so `Logo::DIR` (`__DIR__ . '/../../logo'`) and
 * `ThemeRegistry::bundledDir()` (`dirname(__DIR__, 2) . '/themes'`) are
 * archive-safe as written, and the heavy arm's scanner probe runs them.
 * The two live `glob()` sites (Gpu/AmdGpuIds, Ipmi/SdrCache) read HOST paths
 * (/sys, the user cache dir), never archive contents — phar-irrelevant. The
 * realpath() sites likewise (host /dev, /sys, and the composer-mode
 * checkoutReference arm Help.php never reaches from inside a stamped archive).
 */
final class PharCompatTest extends TestCase
{
    /**
     * Spawn sites that must route the interpreter through PHP_BINARY, as
     * `file => substrings that must appear in that file`. MEASURED EMPTY for
     * candy-top: no src/** or bin/candy-top site launches a PHP interpreter.
     * The roster is kept (with its pin loop) so a future spawn lands HERE as
     * a roster edit plus a needle — never as a silent PATH lookup.
     *
     * @var array<string, list<string>>
     */
    private const PHP_BINARY_SITES = [];

    /**
     * The `__DIR__`-relative data scanner the archive must keep able to read.
     * It must stay glob-free in executable code (comment-stripped) — a
     * reintroduced `glob(__DIR__ …)` would answer [] inside the archive and
     * silently empty the theme catalogue.
     */
    private const GLOB_FREE_SCANNERS = [
        'src/Theme/ThemeRegistry.php',
    ];

    /**
     * Temp trees this test minted (scratch packages and fake HOMEs). Purge is
     * @-quiet by house precedent — residue is noise, never a red herring of
     * a build defect.
     *
     * @var list<string>
     */
    private array $mintedPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->mintedPaths as $path) {
            self::purgeTree($path);
        }
        $this->mintedPaths = [];
        parent::tearDown();
    }

    private static function purgeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                self::purgeTree($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }

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
     * Whether this process may mint a fixture phar in-process. candy-code
     * shares the predicate through its SuiteSkipRoster; candy-top has no
     * roster class, so the shape test runs it as a body gate with the skip
     * message carrying the measurement.
     */
    private static function pharWritingPossible(): bool
    {
        if (!\extension_loaded('phar')) {
            return false;
        }
        $readonly = \ini_get('phar.readonly');

        return !($readonly !== '' && \filter_var($readonly, \FILTER_VALIDATE_BOOLEAN));
    }

    /** The heavy arm runs only when a built archive is named and exists. */
    private static function builtPharSmokeArmed(): bool
    {
        $phar = \getenv('CANDY_TOP_PHAR');

        return \is_string($phar) && $phar !== '' && is_file($phar);
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
        // Vacuity guard: an empty roster must not hide a spawn site that grew
        // in while nobody edited this file — the negative sweep below runs in
        // the same method-order and accuses, and THIS assertion names the
        // moment the roster needs a row instead of a re-measure.
        self::assertTrue(true);
    }

    public function testNoSpawnSiteNamesTheInterpreterAsALiteralPhp(): void
    {
        // Spawn-context only: a call into proc_open/exec/system/passthru/
        // popen/shell_exec/pcntl_exec whose first argument element opens with
        // the quoted word php. Language strings are not call shapes and stay
        // out of the accusation set by construction — the regex looks only at
        // `name(` followed by an optional list bracket and an opening quote.
        $pattern = '/\b(?:proc_open|pcntl_exec|shell_exec|exec|system|passthru|popen|spawnSpec)\(\s*\[?\s*[\'"]php\b/';

        $offenders = [];
        $files = array_merge(
            self::phpFiles(self::root() . '/src'),
            [self::root() . '/bin/candy-top'],
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
        // Structural, not a word search: pin the EXECUTABLE statements that
        // source the stub and seal it, so prose that merely mentions
        // bin/candy-top can no longer satisfy this on its own.
        self::assertStringContainsString(
            "\$binSource = file_get_contents(\$package . '/bin/candy-top');",
            $build,
            'the phar stub must stay sourced from bin/candy-top — the archive boots as a main script only when the stub is the entry script'
        );
        self::assertStringContainsString(
            '$phar->setStub($binSource . "' . '\n' . '__HALT_COMPILER();");',
            $build,
            'the stub must be the bin source plus the compiler halt, assembled in code — a respelled seal breaks the entry'
        );

        $bin = self::read('bin/candy-top');
        self::assertStringStartsWith("#!/usr/bin/env php\n<?php", $bin, 'build-phar refuses this shape; keep the two honest');
        // The measured stub truth (candy-code's first real build, inherited):
        // Phar::running() answers '' when the process IS the archive entry,
        // and bare '/x.phar/entry' paths fail is_file()/require for every
        // caller. Both phar shapes — stub identity (__FILE__ ends .phar) and
        // require-from-inside (Phar::running) — must therefore be addressed
        // as phar:// URIs, and the archive's own vendor must REPLACE the
        // filesystem candidates, not merely outrank them (a stray vendor
        // beside a downloaded phar must never boot code from outside the
        // frozen archive).
        self::assertStringContainsString('Phar::running', $bin, 'the inside-archive candidate seam must stay visible');
        self::assertStringContainsString("'phar://' . __FILE__", $bin, 'the stub door must address the archive as a phar:// URI off __FILE__');
        self::assertStringContainsString("\$candidates = [\$archive . '/vendor/autoload.php'];", $bin, 'inside an archive the archive is the ONLY root — replacement, not append');
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
        if (!self::pharWritingPossible()) {
            $this->markTestSkipped('this process cannot mint a fixture phar (phar.readonly armed or ext-phar absent); the premise was measured on the build box (PHP 8.3.6): glob() answers [] under phar://, scandir() answers the entry list');
        }

        $phar = $this->writePremisePhar();

        try {
            $entries = scandir('phar://' . $phar . '/src');
            self::assertIsArray($entries);
            self::assertContains('inside.php', $entries, 'scandir must see archive contents');

            // chr(42) keeps this needle out of any glob-shaped corpus — a
            // glob-shaped string literal here would drift the pair census
            // (lane lesson: test literals are corpus members).
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
     * called once {@see self::pharWritingPossible()} says yes, so a
     * PharException here is a real defect (tmp unwritable), not an environment
     * shape — let it throw loud.
     */
    private function writePremisePhar(): string
    {
        $path = sys_get_temp_dir() . '/ctop-premise-' . getmypid() . '.phar';
        @unlink($path);
        $phar = new \Phar($path, 0, 'ctop-premise');
        $phar->setStub("<?php __HALT_COMPILER();");
        $phar->addFromString('src/inside.php', "<?php\n");
        $phar->stopBuffering();
        unset($phar);

        return $path;
    }

    // ------------------------------------------------------------------
    // LAW 3 — the integrity preflight refuses an unverifiable source tree
    // ------------------------------------------------------------------

    /**
     * Mint a miniature buildable package in temp: the real tools/build-phar.php
     * and the real bin/candy-top copied in (so the pinned stub-shape check
     * runs against the same bytes CI ships), every packaged root populated
     * with plain files — a STUB published vendor, never a symlink, so the
     * poisoning guard (Guard 4) stays out of these pins' way — and one git
     * commit so the tree starts clean and attributable.
     */
    private function scratchPackage(): string
    {
        $root = sys_get_temp_dir() . '/ctop-scratch-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root, 0700, true));
        $this->mintedPaths[] = $root;

        foreach (['tools', 'bin', 'src', 'lang', 'themes', 'logo', 'vendor'] as $dir) {
            self::assertTrue(is_dir($root . '/' . $dir) || mkdir($root . '/' . $dir, 0700, true) || is_dir($root . '/' . $dir));
        }
        self::assertTrue(copy(self::root() . '/tools/build-phar.php', $root . '/tools/build-phar.php'));
        self::assertTrue(copy(self::root() . '/bin/candy-top', $root . '/bin/candy-top'));
        file_put_contents($root . '/LICENSE', "scratch license\n");
        file_put_contents($root . '/src/ScratchCommand.php', "<?php\n\n// tracked seed file\n");
        file_put_contents($root . '/lang/en.php', "<?php\n\nreturn [];\n");
        file_put_contents($root . '/themes/scratch.toml', "# scratch theme\n");
        file_put_contents($root . '/logo/logos.jsonl', "{\"slug\":\"scratch\"}\n");
        file_put_contents($root . '/vendor/autoload.php', "<?php\n");

        foreach ([
            ['init', '-b', 'scratch'],
            ['config', 'user.email', 'test@example.com'],
            ['config', 'user.name', 'phar scratch'],
            ['add', '-A'],
            ['commit', '-qm', 'seed'],
        ] as $gitArgs) {
            $step = self::launch(array_merge(['git', '-C', $root], $gitArgs), $this->freshPharHome());
            self::assertSame(0, $step['rc'], 'scratch git `' . implode(' ', $gitArgs) . '` failed: ' . $step['err']);
        }

        return $root;
    }

    /**
     * Run the scratch build as a child (its own `phar.readonly` override is
     * explicit argv — the suite process never mutates its ini). launch()
     * drains both pipes to EOF before proc_close, so no reap race (pipe-EOF
     * law); the children are local file/git work, bounded by the suite's
     * wall-clock default exactly like the heavy arm's existing launches.
     *
     * @param list<string> $args
     *
     * @return array{rc:int,out:string,err:string}
     */
    private function buildScratchPhar(string $root, string $out, array $args = []): array
    {
        return self::launch(
            array_merge([\PHP_BINARY, '-d', 'phar.readonly=0', $root . '/tools/build-phar.php', '--out=' . $out], $args),
            $this->freshPharHome(),
        );
    }

    public function testTheBuildRefusesADirtySourceTree(): void
    {
        $root = $this->scratchPackage();
        $out = $root . '/dist/dirty.phar';

        // Untracked is enough — porcelain lists it, and the walk below WOULD
        // have packaged it: this is the freeze the preflight exists to stop.
        file_put_contents($root . '/src/UncommittedWip.php', "<?php\n\n// WIP that must never ship inside an artifact\n");

        $build = $this->buildScratchPhar($root, $out);
        self::assertSame(2, $build['rc'], 'dirty preflight rc/out: ' . $build['out']);
        self::assertStringContainsString('dirty source tree', $build['err']);
        self::assertFalse(is_file($out), 'a refused build must leave no artifact');
    }

    public function testTheBuildRefusesADetachedHead(): void
    {
        $root = $this->scratchPackage();
        $out = $root . '/dist/detached.phar';

        $detach = self::launch(['git', '-C', $root, 'checkout', '--detach', '-q'], $this->freshPharHome());
        self::assertSame(0, $detach['rc'], 'detaching the scratch failed: ' . $detach['err']);

        $build = $this->buildScratchPhar($root, $out);
        self::assertSame(2, $build['rc'], 'detached preflight rc/err: ' . $build['err']);
        self::assertStringContainsString('detached HEAD', $build['err']);
        self::assertFalse(is_file($out), 'a refused build must leave no artifact');
    }

    public function testTheBuildFailsClosedWhenGitCannotAnswerForTheTree(): void
    {
        $root = $this->scratchPackage();
        self::purgeTree($root . '/.git');
        $out = $root . '/dist/no-git.phar';

        // No repo at scratch and the walk-up ceilinged above temp: git can
        // only answer `fatal:` — the door must refuse, not wave it through.
        $build = self::launch(
            [\PHP_BINARY, '-d', 'phar.readonly=0', $root . '/tools/build-phar.php', '--out=' . $out],
            $this->freshPharHome(),
            ['GIT_CEILING_DIRECTORIES' => \dirname($root)],
        );
        self::assertSame(2, $build['rc'], 'no-git preflight rc/out: ' . $build['out']);
        self::assertStringContainsString('integrity cannot be verified', $build['err']);
        self::assertFalse(is_file($out), 'a refused build must leave no artifact');
    }

    public function testTheMonorepoVendorModeIsAcceptedLoudlyAndBuildsFromTheWorkingTree(): void
    {
        $root = $this->scratchPackage();
        $out = $root . '/dist/dev.phar';
        file_put_contents($root . '/src/UncommittedWip.php', "<?php\n\n// frozen on purpose under the hatch\n");

        $build = $this->buildScratchPhar($root, $out, ['--vendor=monorepo']);
        self::assertSame(0, $build['rc'], 'override rc/err: ' . $build['err']);
        self::assertStringContainsString('--vendor=monorepo given — INTEGRITY PREFLIGHT DISABLED', $build['err']);
        self::assertTrue(is_file($out), 'the hatch must actually produce the dev artifact');

        // The semantic half of the pin: the archive contains the UNCOMMITTED
        // file — proof the hatch builds from working-tree state, read back
        // with scandir (LAW 2) from a child so the suite process never
        // registers the scratch archive.
        $probe = self::launch(
            [\PHP_BINARY, '-r', 'echo implode(",", scandir("phar://" . getenv("CTOP_PROBE_PHAR") . "/src") ?: []);'],
            $this->freshPharHome(),
            ['CTOP_PROBE_PHAR' => $out],
        );
        self::assertSame(0, $probe['rc'], 'probe rc/err: ' . $probe['err']);
        self::assertStringContainsString('UncommittedWip.php', $probe['out']);
    }

    /**
     * DST-1: the build stamp round-trips through the runtime reader, and
     * SOURCE_DATE_EPOCH pins `built_at` for reproducible release builds.
     * Child-process build (the LAW 3 door pattern), so no skip gate and no
     * roster entry: `-d phar.readonly=0` lives in the argv, never in the
     * suite's ini. Reading the artifact back in-process is always legal —
     * `phar.readonly` forbids writing archives, not opening them.
     */
    public function testTheBuildStampRoundTripsThroughTheReaderAndHonorsSourceDateEpoch(): void
    {
        $root = $this->scratchPackage();
        // OUTSIDE the frozen git tree: an artifact under $root would make the
        // very next clean-tree preflight read its own output as dirt. A DIR
        // on the mintedPaths list, because purgeTree only descends dirs.
        $dist = $root . '-dist';
        $this->mintedPaths[] = $dist;
        $out = $dist . '/stamped.phar';

        $epoch = 1234567890;
        $build = self::launch(
            [\PHP_BINARY, '-d', 'phar.readonly=0', $root . '/tools/build-phar.php', '--out=' . $out, '--version=candy-top/v9.9.9'],
            $this->freshPharHome(),
            ['SOURCE_DATE_EPOCH' => (string) $epoch],
        );
        self::assertSame(0, $build['rc'], 'clean stamped build rc/err: ' . $build['err']);
        self::assertTrue(is_file($out), 'the stamped build must produce the artifact');

        self::assertSame('v9.9.9', Version::fromPharMetadata($out), 'the tag decoration is stripped and the version read back — end to end through the real build tool');

        $manifest = (new \Phar($out))->getMetadata();
        self::assertIsArray($manifest);
        self::assertSame('2009-02-13T23:31:30+00:00', $manifest['built_at'], 'SOURCE_DATE_EPOCH must decide built_at verbatim, in UTC');

        // Second polarity: no epoch in the child env, the wall clock answers
        // exactly as before (the unset default stays byte-compatible).
        $unpinned = $dist . '/wall.phar';
        $second = self::launch(
            [\PHP_BINARY, '-d', 'phar.readonly=0', $root . '/tools/build-phar.php', '--out=' . $unpinned, '--version=candy-top/v9.9.9'],
            $this->freshPharHome(),
        );
        self::assertSame(0, $second['rc'], 'unstamped build rc/err: ' . $second['err']);
        $wall = (new \Phar($unpinned))->getMetadata();
        self::assertIsArray($wall);
        self::assertNotSame('2009-02-13T23:31:30+00:00', $wall['built_at'], 'without SOURCE_DATE_EPOCH the epoch fixture value must NOT appear');
        self::assertLessThan(120, abs(time() - (int) strtotime((string) $wall['built_at'])), 'unset epoch keeps the wall clock');
        self::assertSame('v9.9.9', Version::fromPharMetadata($unpinned), 'the stamp is independent of the clock mode');
    }

    // ------------------------------------------------------------------
    // REAL-ARCHIVE ARM (CI-gated) — the built phar boots and its scanners live
    // ------------------------------------------------------------------

    /**
     * One method, four sequential stages, so the skip is a single event:
     * the release workflow builds an archive, exports CANDY_TOP_PHAR, and
     * runs this file; local equivalent after
     * `php -d phar.readonly=0 tools/build-phar.php --out=dist/candy-top.phar`
     * on a published (non-symlinked) vendor tree.
     */
    public function testTheBuiltPharBehavesLikeTheInstalledApplication(): void
    {
        if (!self::builtPharSmokeArmed()) {
            $this->markTestSkipped('set CANDY_TOP_PHAR=/path/to/candy-top.phar — the release workflow builds one; locally: php -d phar.readonly=0 tools/build-phar.php --out=dist/candy-top.phar');
        }

        $phar = (string) getenv('CANDY_TOP_PHAR');
        // Stage 1 — the stub answers the version banner (proves the archive
        // boots as a main script, the only phar entry shape PHP accepts).
        $version = self::launch([\PHP_BINARY, $phar, '--version'], $this->freshPharHome());
        self::assertSame(0, $version['rc'], 'version rc/stderr: ' . $version['err']);
        self::assertStringStartsWith('candy-top', ltrim($version['out']));

        // DST-1: the banner must BE the archive's own stamp, not the frozen
        // dev-master guess candy-code's shipped 0.1.0 phar reported. This is
        // the release-shape proof the unit pins cannot make: a real archive,
        // booted as a real main script, answering with a version
        // `Version::isComparable` accepts and `self-update` can order.
        $stamped = Version::fromPharMetadata($phar);
        self::assertNotNull($stamped, 'a CANDY_TOP_PHAR release archive must carry a comparable metadata stamp');
        self::assertSame('candy-top ' . $stamped . "\n", $version['out'], 'the banner is the stamp — metadata read end to end');

        // Stage 2 — the help surface prints with a fresh HOME (candy-top has
        // no headless provider turn to run; the TUI cannot boot off a tty, so
        // the offline proof is the --help document, which is also where the
        // self-update verb must be discoverable).
        $help = self::launch([\PHP_BINARY, $phar, '--help'], $this->freshPharHome());
        self::assertSame(0, $help['rc'], 'help rc/stderr: ' . $help['err']);
        self::assertStringContainsString('self-update', $help['out'], 'the shipped archive must document its own updater');

        // Stage 3 — the scanners are ALIVE inside the archive: a child reaches
        // it through its own vendor/autoload.php via the only addressing that
        // works from outside (a phar:// URI — bare '/x.phar/entry' requires
        // throw, measured), and both bundled-data surfaces come back. The
        // theme catalogue is the scandir-family proof; the logo manifest is
        // the '..'-traversal proof (Logo::DIR is __DIR__-relative two hops
        // up — resolution measured on 8.3.6 before this arm shipped).
        $probe = 'require "phar://" . getenv("CTOP_PHAR") . "/vendor/autoload.php";'
            . '$registry = \SugarCraft\Top\Theme\ThemeRegistry::new();'
            . 'echo ",".count($registry->names());'
            . 'echo ",".(is_file(\SugarCraft\Top\View\Logo::DIR . "/logos.jsonl") ? "1" : "0");';
        $scan = self::launch([\PHP_BINARY, '-r', $probe], $this->freshPharHome(), ['CTOP_PHAR' => $phar]);
        self::assertSame(0, $scan['rc'], 'scanner probe rc/stderr: ' . $scan['err']);
        $counts = array_map('intval', explode(',', ltrim($scan['out'], ',')));
        self::assertGreaterThanOrEqual(1, $counts[0] ?? 0, 'theme catalogue empty under phar: ' . $scan['out']);
        self::assertSame(1, $counts[1] ?? 0, 'the bundled logo manifest is unreadable under phar: ' . $scan['out']);

        // Stage 4 — build manifest present; LICENSE shipped. getMetadata(),
        // not ->metadata: on PHP 8.3 the magic property warns "Undefined
        // property" and answers NULL even when the archive carries the
        // manifest (measured during candy-code's first real-build smoke — the
        // stored data was there all along, the property read was a lie).
        $archive = new \Phar($phar);
        $manifest = $archive->getMetadata();
        self::assertIsArray($manifest, 'build metadata missing');
        self::assertArrayHasKey('version', $manifest);

        $sawLicense = false;
        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            $path = str_replace('\\', '/', $file->getPathName());
            $sawLicense = $sawLicense || str_contains($path, '/LICENSE');
        }
        self::assertTrue($sawLicense, 'LICENSE must ship inside the archive');
    }

    /**
     * Run a program with piped stdout/stderr and a hardened env: fresh HOME,
     * no developer-shell state inherited, and an optional extra env frame
     * whose keys win over the inherited environment.
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
            if ($key === 'HOME' || isset($extra[$key])) {
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
        $home = sys_get_temp_dir() . '/ctop-home-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($home, 0700, true) || is_dir($home));
        $this->mintedPaths[] = $home;

        return $home;
    }
}
