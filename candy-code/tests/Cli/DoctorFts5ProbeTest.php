<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\DoctorChecks;

/**
 * SES-2: the doctor carries an fts5 row that ASKS THE DATABASE the question
 * session body search will actually ask — `CREATE VIRTUAL TABLE ... USING
 * fts5` followed by a real `MATCH`, run against `sqlite::memory:` in the
 * ISS-083 doctrine. FTS5 is a compile-time option of SQLite itself, not a
 * PHP extension, so `extension_loaded()` could not answer the question even
 * in principle; and a build that creates the table but cannot search it is
 * broken exactly as loudly as one that cannot create it.
 *
 * The row is WARN, never FAIL: with the module absent,
 * {@see \SugarCraft\Code\Session\SessionSearchIndex} latches and
 * `withBodySearch` answers through the LIKE prefilter — a degraded feature,
 * not a broken install. Both halves of that promise are pinned here: the
 * healthy answer, and the refusal shape when the module name is a lie.
 */
final class DoctorFts5ProbeTest extends TestCase
{
    /** A module no SQLite build spells: the probe must degrade, never throw. */
    private const ABSENT_MODULE = 'no_such_fts5_module_lane_w5g';

    /** @return array<string, \Closure(): array{status: string, detail: string}> */
    private function doctorRows(): array
    {
        $method = new \ReflectionMethod(DoctorChecks::class, 'probes');
        $method->setAccessible(true);

        /** @var array<string, \Closure> $rows */
        $rows = $method->invoke(null, null);

        return $rows;
    }

    /** @return array{status: string, detail: string} */
    private function probeWithModule(string $module): array
    {
        $method = new \ReflectionMethod(DoctorChecks::class, 'fts5Probe');
        $method->setAccessible(true);

        /** @var array{status: string, detail: string} $row */
        $row = $method->invoke(null, $module);

        return $row;
    }

    public function testTheFts5RowSitsDirectlyBehindTheDriverItRunsOn(): void
    {
        $labels = array_keys($this->doctorRows());

        $this->assertContains('fts5', $labels);
        $pdo = (int) array_search('pdo_sqlite', $labels, true);
        $fts = (int) array_search('fts5', $labels, true);
        $curl = (int) array_search('ext-curl', $labels, true);
        $this->assertSame($pdo + 1, $fts, 'the capability question belongs next to the driver question it presumes');
        $this->assertLessThan($curl, $fts);
    }

    public function testTheLiveProbeAnswersOkWithTheLangDetailOnThisRuntime(): void
    {
        $rows = $this->doctorRows();
        $row = ($rows['fts5'])();

        $this->assertSame('OK', $row['status'], $row['detail']);
        // Pins the lang key wiring too: a typo'd key would fall through to
        // the raw key string and fail this exact compare.
        $this->assertSame('full-text session search available', $row['detail']);
    }

    public function testAnAbsentModuleAnswersWarnAndNeverFail(): void
    {
        $row = $this->probeWithModule(self::ABSENT_MODULE);

        $this->assertSame('WARN', $row['status'], 'body search degrades to LIKE; the install is not broken');
        $this->assertStringContainsString('degrades to the name/preview prefilter', $row['detail']);
        $this->assertStringContainsString('no such module', $row['detail'], 'the SQLite error rides the detail line verbatim');
    }

    public function testTheProbeBuildsAndReadsTheTableInsteadOfNamingTheExtension(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/DoctorChecks.php');
        $start = (int) strpos($src, 'private static function fts5Probe');
        $this->assertGreaterThan(0, $start, 'the probe body must be findable by name');
        $rest = substr($src, $start);
        $slice = substr($rest, 0, (int) strpos($rest, "\n    }"));

        // The exact statements the session index issues, plus a read-back.
        $this->assertStringContainsString('CREATE VIRTUAL TABLE candy_code_fts5_probe USING ', $slice);
        $this->assertStringContainsString('WHERE body MATCH', $slice);

        // No name check could stand in for the exercise here.
        $this->assertSame(0, substr_count($slice, 'extension_loaded('));
        $this->assertSame(0, substr_count($slice, "'FAIL'"));
        $this->assertSame(1, substr_count($slice, "'WARN'"));
    }
}
