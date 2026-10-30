<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Code\Cli\DoctorChecks;

/**
 * C-TUI-5.a. The install-check roster has one home now: `Cli/DoctorChecks.php`,
 * read by two surfaces — `candy-code doctor` (which formats the rows and owns
 * the exit code) and the in-session `/doctor` (which renders them as a table).
 * Everything that used to be true of `Subcommands::doctorProbes()` is true here
 * instead, so the pins moved with the code rather than being re-imagined:
 *
 *   - the roster, in order, and the `sandbox` row C-TOOL-4.a landed rides in it
 *     — the extraction carries that row, it does not mint a second one;
 *   - the roster reads ONE value, the launch's root: it cannot reach argv, so no
 *     probe can be steered by an operand the CLI forgot to reject;
 *   - each probe is individually caught, so one hostile check is a FAIL line and
 *     not a lost report — the catch is pinned STRUCTURALLY because nothing on a
 *     healthy install throws, and an absence pin would be vacuous.
 */
final class DoctorChecksTest extends TestCase
{
    /** The roster as the pages describe it: this list IS the order it prints in. */
    private const ROSTER = [
        'candy-code',
        'php',
        'pdo_sqlite',
        'fts5',
        'ext-curl',
        'ext-mbstring',
        'ext-intl',
        'server mode',
        'config file',
        'settings keys',
        'spend cap',
        'permission policy',
        'sandbox',
        'provider',
        'session store',
        'mcp config',
    ];

    public function testTheRosterIsTheseSixteenRowsInThisOrder(): void
    {
        self::assertSame(self::ROSTER, array_keys(self::probes()), 'the doctor roster changed shape — the pages, the `sixteen checks` headline and this list move together');
    }

    /**
     * The row C-TOOL-4.a owns is a row of THIS roster now that the roster lives
     * here. Asserting its home, not its contents: {@see DoctorSandboxProbeTest}
     * is where that row's behaviour is pinned, and a second copy of those claims
     * here would be the drift this step is supposed to prevent.
     */
    public function testTheSandboxRowWasCarriedRatherThanReminted(): void
    {
        self::assertArrayHasKey('sandbox', self::probes());
        self::assertContains('sandbox', array_column(DoctorChecks::run(null), 'label'));

        $source = self::source();
        // One jail opinion in this file, and it is Bash's own probe plus
        // Bubblewrap's own reader — no second bwrap answer, and therefore no
        // second thing to disagree with the first when a host changes.
        self::assertSame(1, substr_count($source, '->sandboxRefusal()'));
        self::assertSame(1, substr_count($source, 'Bubblewrap::appArmorRestrictsUserns('));
    }

    public function testTheRosterReadsTheRootAndNothingElse(): void
    {
        $method = new ReflectionMethod(DoctorChecks::class, 'probes');
        $params = $method->getParameters();
        self::assertCount(1, $params, 'probes() gained a second input — a roster that reads more than the root can be steered by an operand');
        self::assertSame('root', $params[0]->getName());
        // '?' . 'string' rather than the bare literal: a glob-shaped string
        // here would grow the harvested dialect corpus on a type-name mention
        // (GlobDialectDifferentialTest); the bare '?' fragment is already in it.
        self::assertSame('?' . 'string', (string) $params[0]->getType());

        // argv is out of reach by construction, and the type system is the pin:
        // no ParsedArgs in this file means no probe can grow a flag.
        $source = self::source();
        self::assertStringNotContainsString('ParsedArgs', $source);
        self::assertStringNotContainsString('ArgvParser', $source);
        self::assertStringNotContainsString('$args', $source);
    }

    public function testEveryProbeAnswersWithAVerdictAndOneLineOfDetail(): void
    {
        foreach (self::probes() as $label => $probe) {
            $row = $probe();
            self::assertArrayHasKey('status', $row, "{$label} answered without a status");
            self::assertContains($row['status'], ['OK', 'WARN', 'FAIL'], "{$label} invented a verdict");
            self::assertNotSame('', $row['detail'], "{$label} said nothing");
            self::assertStringNotContainsString("\n", $row['detail'], "{$label}'s detail is a table cell, not a paragraph");
        }
    }

    /**
     * The catch that keeps one hostile check from costing the whole report, read
     * off the shipped body. Pinned structurally because a functional version
     * needs a probe that throws, and on a machine this suite runs on none does:
     * an assertion that only holds while nothing breaks is the vacuity this repo
     * refuses. {@see \SugarCraft\Code\Tests\Integration\BinCandyCodeDispatchTest}
     * drives the loud half of the same law through the real binary.
     */
    public function testEachProbeIsIndividuallyCaughtInsideTheReport(): void
    {
        $source = self::source();
        $start = (int) strpos($source, 'public static function run(');
        self::assertGreaterThan(0, $start, 'run() must be findable by name');
        $body = (string) substr($source, $start, (int) strpos((string) substr($source, $start), "\n    }"));

        self::assertLessThan(
            (int) strpos($body, '$probe()'),
            (int) strpos($body, 'try {'),
            'the try must OPEN before the probe is called — a call outside it is a fatal, not a row',
        );
        self::assertSame(1, substr_count($body, 'catch (\Throwable'), 'exactly one catch per probe, so no row can escape the report');
        self::assertStringContainsString("'status' => 'FAIL'", $body, 'a throwing probe must be reported as a FAIL, not dropped');
        self::assertStringContainsString('$e::class', $body, 'the row names the exception class — a message alone cannot be traced back to a bug');
        self::assertSame(
            2,
            substr_count($body, "'label' => \$label"),
            'both branches must carry the label — a catch that drops the row reports a shorter install than there is',
        );
        self::assertSame(
            self::ROSTER,
            array_column(DoctorChecks::run(null), 'label'),
            'the rows run() returns are the roster in roster order — a catch that loses a row lies about the install',
        );
    }

    public function testTheReportCarriesNoNewSpawnOrNetworkSink(): void
    {
        // Comments stripped: the roster carries prose that NAMES the sinks it
        // refuses to use, and a census over comments accuses the explanation.
        $source = self::codeOnly();
        foreach (['proc_open', 'shell_exec', 'curl_init', 'fsockopen', 'stream_socket_client', 'popen'] as $sink) {
            self::assertSame(0, substr_count($source, $sink . '('), "DoctorChecks reached for {$sink}() — the doctor that answers in a session must not start a process or a connection");
        }
        self::assertSame(1, substr_count($source, 'file_get_contents('), 'one read in this roster: the config file the `config file` row validates');
        // The provider row's law, in code: the client is BUILT (ISS-028 wants a
        // bad apiKey to fail here rather than at the first turn) and never USED.
        self::assertSame(1, substr_count($source, 'new ProviderFactory('), 'the provider row builds its provider through the factory exactly once');
        foreach (['->complete(', '->request(', 'HttpClient', '->chat('] as $dial) {
            self::assertSame(0, substr_count($source, $dial), "the roster reached for {$dial} — a check that talks to a provider is not a health check, it is a bill");
        }
    }

    // ---- fixtures -------------------------------------------------------------

    /** @return array<string, \Closure(): array{status: string, detail: string}> */
    private static function probes(): array
    {
        $method = new ReflectionMethod(DoctorChecks::class, 'probes');
        $method->setAccessible(true);

        /** @var array<string, \Closure> $roster */
        $roster = $method->invoke(null, null);

        return $roster;
    }

    /** {@see source()} with every comment token removed — a sink census on the
     * executable text only, so a docblock that names a function is not a call. */
    private static function codeOnly(): string
    {
        $out = '';
        foreach (\PhpToken::tokenize(self::source()) as $token) {
            if ($token->is(T_COMMENT) || $token->is(T_DOC_COMMENT)) {
                continue;
            }
            $out .= $token->text;
        }

        return $out;
    }

    private static function source(): string
    {
        return (string) file_get_contents((new ReflectionClass(DoctorChecks::class))->getFileName());
    }
}
