<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\DoctorChecks;

/**
 * rv-enduser-6 / rv-devops-6 (fix 9): the doctor used to report a box green
 * while the two extensions the painted path runs through on every frame —
 * mbstring (grapheme maths behind `Message`/`Renderer`) and intl
 * (`grapheme_extract()`, `Normalizer`) — were absent, because neither was
 * probed at all, and the manifest did not declare them either.
 *
 * The doctrine these pins hold to is the doctor's own, spelled out above
 * `pdoDriverProbe()`: EXERCISED, not name-checked. A probe that calls
 * `extension_loaded()` encodes a name a mutation can swap; a probe that
 * performs the real call fails the moment the capability does. So this file
 * asserts BOTH directions: the live probes answer OK on a healthy box, and
 * the probe source contains the exercised call and not the name check.
 *
 * The `pdo_sqlite` half of the review ask was already true when this lane
 * measured it — the probe opens the exact `sqlite::memory:` DSN
 * SessionStore builds, and the bare `ext-sqlite3` check survives only as a
 * parenthetical in its detail line. Row 5 pins that shape so a regression to
 * name-checking cannot hide behind the newer probes.
 */
final class DoctorExtensionProbesTest extends TestCase
{
    /** @return array<string, \Closure(): array{status: string, detail: string}> */
    private function probes(): array
    {
        $method = new \ReflectionMethod(DoctorChecks::class, 'probes');
        $method->setAccessible(true);

        /** @var array<string, \Closure> $probes */
        $probes = $method->invoke(null, null);

        return $probes;
    }

    public function testTheDoctorCarriesTheTwoExtensionProbesInTheCurlNeighbourhood(): void
    {
        $labels = array_keys($this->probes());

        $this->assertContains('ext-mbstring', $labels);
        $this->assertContains('ext-intl', $labels);

        $curl = (int) array_search('ext-curl', $labels, true);
        $mb = (int) array_search('ext-mbstring', $labels, true);
        $intl = (int) array_search('ext-intl', $labels, true);
        $server = (int) array_search('server mode', $labels, true);
        $this->assertLessThan($mb, $curl, 'the extension probes belong beside ext-curl');
        $this->assertLessThan($intl, $mb);
        $this->assertLessThan($server, $intl, 'and before the server-mode block, keeping the runtime checks grouped');
    }

    public function testBothProbesAnswerOkOnAHealthyRuntime(): void
    {
        $probes = $this->probes();

        foreach (['ext-mbstring', 'ext-intl'] as $label) {
            $row = ($probes[$label])();
            $this->assertSame('OK', $row['status'], "{$label}: " . $row['detail']);
            $this->assertSame('loaded', $row['detail']);
        }
    }

    public function testTheProbesExerciseTheCallRatherThanNameTheExtension(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/DoctorChecks.php');
        $start = (int) strpos($src, "'ext-mbstring' =>");
        $end = (int) strpos($src, "'server mode' =>");
        $this->assertGreaterThan($start, $end, 'both probe entries live in one slice of the map');
        $slice = substr($src, $start, $end - $start);

        // The real calls, each performed once inside its try — this is the
        // assertion a name-check-only doctor could never pass.
        $this->assertSame(1, substr_count($slice, '\\mb_substr_count('));
        $this->assertSame(1, substr_count($slice, '\\IntlChar::ord('));

        // And no name check pretending to be a probe in this slice.
        $this->assertSame(0, substr_count($slice, 'extension_loaded('), 'these two probes must not degrade to name checks (ext-curl above them may: it is a suggest)');

        // Declared extensions fail LOUD — FAIL, never WARN.
        $this->assertSame(2, substr_count($slice, "'FAIL'"));
        $this->assertSame(0, substr_count($slice, "'WARN'"));
    }

    /**
     * Reads the COMMITTED blob, not the working copy: CI runs
     * `php tools/check-path-repos.php --fix --strict-closure` before install,
     * which injects a `repositories[]` block into the working-tree lib
     * manifests — a file-read here would go red in CI while staying green
     * locally. The law under test (AGENTS.md composer rule) binds the
     * committed manifest, so the committed manifest is what gets probed.
     */
    public function testTheManifestDeclaresBothExtensionsAndCarriesNoRepositoriesBlock(): void
    {
        $committed = shell_exec('git -C ' . escapeshellarg(\dirname(__DIR__, 2)) . ' show HEAD:candy-code/composer.json');
        $this->assertNotNull($committed, 'git show HEAD:candy-code/composer.json produced no output — is this checkout a git work tree?');

        $manifest = json_decode(
            $committed,
            true,
            512,
            \JSON_THROW_ON_ERROR
        );

        $this->assertSame('*', $manifest['require']['ext-mbstring'] ?? null);
        $this->assertSame('*', $manifest['require']['ext-intl'] ?? null);
        $this->assertArrayNotHasKey('repositories', $manifest, 'a lib manifest never carries path repositories (AGENTS.md composer rule)');
    }

    public function testTheSqliteCheckIsStillTheOpenedDsnNotTheExtensionName(): void
    {
        $probes = $this->probes();
        $this->assertArrayHasKey('pdo_sqlite', $probes);

        $dsn = (new \ReflectionClass(DoctorChecks::class))->getConstant('SESSION_STORE_PROBE_DSN');
        $this->assertSame('sqlite::memory:', $dsn, 'the probe must open the driver SessionStore actually uses');

        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/DoctorChecks.php');
        $pdoSlice = substr($src, (int) strpos($src, 'private static function pdoDriverProbe'));
        $pdoSlice = substr($pdoSlice, 0, (int) strpos($pdoSlice, "\n    }"));
        $this->assertStringContainsString('new \\PDO($dsn)', $pdoSlice);

        // ISS-083: the sqlite3 half of the row is the EXERCISED construction
        // TaskList performs, not a returned `extension_loaded` string — a
        // regression to name-checking must not hide behind the DSN probe.
        $this->assertStringContainsString('self::sqlite3Exercise()', $pdoSlice);
        $this->assertStringNotContainsString("extension_loaded('sqlite3')", $pdoSlice);
    }
}
