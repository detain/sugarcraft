<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * The guard for `scripts/paratest-conservation.php`, the check CI's candy-code
 * cells run (through `scripts/paratest.sh --against-json`) between a ParaTest
 * run's junit log and the pinned suite-figure.json.
 *
 * It only ever runs at the end of a full suite, so a regression in it would
 * otherwise surface as a wrong verdict after a 15-minute CI wall, or as no
 * verdict at all. Every exit path is pinned here against fixture logs: a
 * matching run passes, each kind of miss fails, and input it cannot read is
 * exit 2, never a pass.
 *
 * @internal
 */
final class ParatestConservationTest extends TestCase
{
    use TmpTree;

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('paratest_conservation_');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testATreeThatMatchesTheFigurePasses(): void
    {
        [$code, $out] = $this->check($this->junit(tests: 3, assertions: 7, failures: 1, skippedElements: 1), $this->figure(3, 7, 1, 1));

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('CONSERVATION: PASS', $out);
    }

    public function testARunThatLostATestFails(): void
    {
        [$code, $out] = $this->check($this->junit(tests: 3, assertions: 7), $this->figure(4, 7, 0, 0));

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('delta:  tests=-1 assertions=+0', $out);
        $this->assertStringContainsString('CONSERVATION: FAIL', $out);
    }

    public function testEachOtherTotalCanFailOnItsOwn(): void
    {
        $junit = $this->junit(tests: 3, assertions: 7, failures: 1, skippedElements: 1);
        foreach ([
            'assertions' => $this->figure(3, 8, 1, 1),
            'failures' => $this->figure(3, 7, 0, 1),
            'skipped' => $this->figure(3, 7, 1, 0),
        ] as $total => $figure) {
            [$code, $out] = $this->check($junit, $figure);
            $this->assertSame(1, $code, "a {$total} miss must fail: {$out}");
        }
    }

    public function testAnErrorFailsEvenWhenTheOtherTotalsMatch(): void
    {
        [$code, $out] = $this->check($this->junit(tests: 3, assertions: 7, errors: 1), $this->figure(3, 7, 0, 0));

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('errors=1', $out);
    }

    public function testSkippedIsCountedFromTheTestcasesNotTheRootAttribute(): void
    {
        // scripts/parallel-tests.sh counts <testcase><skipped/> elements; the
        // two checks have to agree on what one figure means.
        [$code, $out] = $this->check($this->junit(tests: 3, assertions: 7, skippedElements: 1, skippedAttribute: 2), $this->figure(3, 7, 0, 1));

        $this->assertSame(0, $code, $out);
    }

    public function testInputItCannotReadIsExitTwoNeverAPass(): void
    {
        $figure = $this->figure(3, 7, 0, 0);
        $truncated = $this->tmpDir . '/truncated.xml';
        \file_put_contents($truncated, \substr((string) \file_get_contents($this->junit(tests: 3, assertions: 7)), 0, 120));
        $empty = $this->tmpDir . '/empty.xml';
        \file_put_contents($empty, '');
        $noSuite = $this->tmpDir . '/no-suite.xml';
        \file_put_contents($noSuite, "<?xml version=\"1.0\"?>\n<testsuites/>\n");
        $badFigure = $this->tmpDir . '/bad-figure.json';
        \file_put_contents($badFigure, '{"tests": 3, "assertions": 7, "failures": 0}');

        foreach ([
            'missing junit' => [$this->tmpDir . '/absent.xml', $figure],
            'truncated junit' => [$truncated, $figure],
            'empty junit' => [$empty, $figure],
            'junit without a testsuite' => [$noSuite, $figure],
            'figure without skipped' => [$this->junit(tests: 3, assertions: 7), $badFigure],
            'missing figure' => [$this->junit(tests: 3, assertions: 7), $this->tmpDir . '/absent.json'],
        ] as $case => [$junit, $fig]) {
            [$code, $out] = $this->check($junit, $fig);
            $this->assertSame(2, $code, "{$case}: {$out}");
            $this->assertStringNotContainsString('CONSERVATION: PASS', $out, $case);
        }

        [$code] = $this->invoke([]);
        $this->assertSame(2, $code, 'a wrong argument count is a usage error');
    }

    private function junit(int $tests, int $assertions, int $failures = 0, int $errors = 0, int $skippedElements = 0, ?int $skippedAttribute = null): string
    {
        $cases = '';
        for ($i = 0; $i < $tests; $i++) {
            $cases .= $i < $skippedElements
                ? "      <testcase name=\"t{$i}\" class=\"A\" file=\"/x/ATest.php\"><skipped/></testcase>\n"
                : "      <testcase name=\"t{$i}\" class=\"A\" file=\"/x/ATest.php\"/>\n";
        }
        $skippedAttribute ??= $skippedElements;
        $path = $this->tmpDir . '/junit-' . \bin2hex(\random_bytes(3)) . '.xml';
        \file_put_contents($path, <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites name="PHPUnit tests">
              <testsuite name="" tests="{$tests}" assertions="{$assertions}" errors="{$errors}" failures="{$failures}" skipped="{$skippedAttribute}" time="1.5">
                <testsuite name="A" file="/x/ATest.php" tests="{$tests}">
            {$cases}    </testsuite>
              </testsuite>
            </testsuites>

            XML);

        return $path;
    }

    private function figure(int $tests, int $assertions, int $failures, int $skipped): string
    {
        $path = $this->tmpDir . '/figure-' . \bin2hex(\random_bytes(3)) . '.json';
        \file_put_contents($path, \json_encode(['tests' => $tests, 'assertions' => $assertions, 'failures' => $failures, 'skipped' => $skipped], \JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array{int, string} */
    private function check(string $junit, string $figure): array
    {
        return $this->invoke([$junit, $figure]);
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private function invoke(array $args): array
    {
        $script = \dirname(__DIR__, 2) . '/scripts/paratest-conservation.php';
        $process = \proc_open([\PHP_BINARY, $script, ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $this->assertIsResource($process);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);

        return [\proc_close($process), $out];
    }
}
