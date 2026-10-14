<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Subcommands;

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
        $method = new \ReflectionMethod(Subcommands::class, 'doctorProbes');
        $method->setAccessible(true);

        /** @var array<string, \Closure> $probes */
        $probes = $method->invoke(null, ArgvParser::parse(['sugarcrush', 'doctor']));

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
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php');
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

    public function testTheManifestDeclaresBothExtensionsAndCarriesNoRepositoriesBlock(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'),
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

        $dsn = (new \ReflectionClass(Subcommands::class))->getConstant('SESSION_STORE_PROBE_DSN');
        $this->assertSame('sqlite::memory:', $dsn, 'the probe must open the driver SessionStore actually uses');

        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php');
        $pdoSlice = substr($src, (int) strpos($src, 'private static function pdoDriverProbe'));
        $pdoSlice = substr($pdoSlice, 0, (int) strpos($pdoSlice, "\n    }"));
        $this->assertStringContainsString('new \\PDO($dsn)', $pdoSlice);
    }
}
