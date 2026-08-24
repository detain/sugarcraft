<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The seam that replaced {@see SkillLoader}'s per-skip `error_log()`.
 *
 * Taking those lines off stderr was right — they are OTHER TOOLS' files, one
 * broken third-party skill printed on every launch, and a skill scan also runs
 * mid-session on the Ctrl+P provider switch where a stderr write lands inside
 * a frame the renderer believes it owns. But `skipped()` had no caller in
 * `src/` or `bin/` at all, so the replacement was a diagnostic nothing read:
 * the README claimed skips "are also readable from SkillManager::skipped()",
 * which was true of the API and false of the product.
 *
 * Every test here calls `Bootstrap::backend()` and needs the run to land on the
 * ENGINE path, since a shell-out backend performs no skill scan. So the
 * backend-selection chain is cleared for the whole class as well as HOME: with
 * `$SUGARCRUSH_BACKEND_CMD` or `$SUGARCRUSH_BACKEND_CMD_STREAM` merely exported
 * in the developer's shell, two of these tests failed — measured, before the
 * clearing was added.
 */
final class BootstrapSkillSkipsTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    /**
     * THE WALL-CLOCK BUDGET FOR THE REAL CHILD PROCESSES THIS FILE SPAWNS, AND
     * IT IS A CONSTANT SO THE NEXT READER CAN RAISE IT KNOWINGLY.
     *
     * WHAT IT IS FOR. Both helpers below run a real PHP process. A hang there —
     * a launch that waits on a terminal it does not have, a provider that waits
     * on a socket — would otherwise stall the whole suite with no verdict, so
     * `timeout -s KILL` bounds it.
     *
     * WHY IT IS NOT SIXTY, WHICH IS WHAT IT WAS. `phpunit.xml` sets
     * `enforceTimeLimit` with a per-test limit of its own, and PHPUnit enforces
     * that with `pcntl_alarm()` plus a handler that throws. A budget EQUAL to
     * that limit means the two clocks expire together and PHPUnit's wins, since
     * its clock starts before `setUp()` and the child's starts after. When it
     * wins, the test is ABORTED: it is recorded RISKY rather than failed, and
     * every assertion the arm would have made is SHED from the run's totals.
     * That is a strictly worse verdict than a red — a reader gets a count that
     * moved and no statement about what broke.
     *
     * Measured on this host, PHP 8.3.6 / PHPUnit 10.5.64, at load average 6
     * with sibling suites running: one `-p` child costs 0.28s (three takes:
     * 0.28 / 0.28 / 0.28), and the whole file runs in 0.41s. So this budget is
     * ~70x the real cost of the thing it bounds, and sits far enough under the
     * per-test limit that a child which genuinely hangs is SIGKILLed first and
     * reported by {@see assertTheChildWasNotKilledByItsBudget()} as a red that
     * names this constant.
     *
     * RAISING IT IS A REAL TRADE AND IT HAS A CEILING. Above the per-test limit
     * this file stops being able to fail at all, which is the state it was in.
     * {@see testTheChildBudgetStaysUnderThePerTestLimitPhpunitEnforces()} pins
     * that ceiling against `phpunit.xml` so the relation cannot rot silently.
     */
    private const CHILD_WALL_CLOCK_BUDGET_SECONDS = 20;

    /**
     * What a shell reports when `timeout -s KILL` kills the child: 128 + SIGKILL.
     */
    private const KILLED_BY_THE_BUDGET = 137;

    /**
     * How much of the per-test limit must remain unspent by the child budget.
     *
     * Not taste: the gap has to cover everything in the test that is NOT the
     * child — `setUp()`, the sandbox teardown, the process spawn — because
     * PHPUnit's clock is running for all of it and the child's is not.
     */
    private const BUDGET_HEADROOM_SECONDS = 20;

    private string $tempDir;
    private string $home;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_skill_skips_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->project = $this->tempDir . '/project';
        mkdir($this->project, 0700, true);
        $this->useHomeSandbox($this->home);
        $this->clearBackendSelectionEnv();
    }

    protected function tearDown(): void
    {
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /** The launch's skill scan keeps its diagnostic where a caller can ask for it. */
    public function testTheLaunchsSkillSkipsAreReadableFromBootstrap(): void
    {
        $broken = $this->writeBrokenSkill();

        Bootstrap::backend($this->project);

        $this->assertArrayHasKey($broken, Bootstrap::skillSkips());
    }

    /**
     * ...and ONE bounded line tells the user it is there to ask for. One line
     * regardless of how many skips: the failure this replaced was N lines on
     * every launch.
     */
    public function testTheLaunchReportsSkippedSkillsInOneLine(): void
    {
        $this->writeBrokenSkill('one');
        $this->writeBrokenSkill('two');

        $stderr = $this->stderrOfALaunch();

        $this->assertSame(1, substr_count($stderr, 'could not be read'));
        $this->assertStringContainsString('2 skill files', $stderr);
        $this->assertStringContainsString(SkillLoader::DEBUG_SKIPS_ENV, $stderr);
    }

    /** A clean skill tree says nothing at all. */
    public function testACleanLaunchIsSilent(): void
    {
        $this->assertStringNotContainsString('could not be read', $this->stderrOfALaunch());
    }

    /**
     * A `-p` RUN SCANS THE SAME TREES AND USED TO SWALLOW THE NOTICE ENTIRELY.
     * {@see \SugarCraft\Crush\Cli\NonInteractive::run()} builds its backend
     * directly rather than through {@see Bootstrap::chat()}, which was the only
     * caller that reported skips — so the one path with no alt screen to
     * protect was the one path that said nothing.
     */
    public function testAOneShotRunReportsSkippedSkillsToo(): void
    {
        $this->writeBrokenSkill();

        $stderr = $this->stderrOfAOneShotRun();

        $this->assertStringContainsString('could not be read', $stderr);
        $this->assertStringContainsString(SkillLoader::DEBUG_SKIPS_ENV, $stderr);
    }

    /** ...and a clean tree is silent there too. */
    public function testACleanOneShotRunIsSilent(): void
    {
        $this->assertStringNotContainsString('could not be read', $this->stderrOfAOneShotRun());
    }

    /**
     * The real binary, in one-shot mode. The offline echo provider answers, so
     * this needs no credentials and no network.
     */
    private function stderrOfAOneShotRun(): string
    {
        $errFile = $this->tempDir . '/oneshot-stderr.txt';

        $status = 0;
        $output = [];
        exec(sprintf(
            'cd %s && HOME=%s timeout -s KILL %d %s %s -p %s >/dev/null 2>%s',
            escapeshellarg($this->project),
            escapeshellarg($this->home),
            self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bin/sugarcrush'),
            escapeshellarg('hello'),
            escapeshellarg($errFile),
        ), $output, $status);

        $this->assertTheChildWasNotKilledByItsBudget($status, 'the one-shot run');

        return is_file($errFile) ? (string) file_get_contents($errFile) : '';
    }

    private function writeBrokenSkill(string $name = 'broken'): string
    {
        $dir = $this->home . '/.claude/skills/' . $name;
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/SKILL.md', "no frontmatter at all\n");

        return $dir . '/SKILL.md';
    }

    /**
     * A CHILD process, because the notice goes to the STDERR constant — which
     * is the point: it must reach a real user, ahead of the alt screen.
     */
    private function stderrOfALaunch(): string
    {
        $script = $this->tempDir . '/launch.php';
        $errFile = $this->tempDir . '/stderr.txt';

        file_put_contents($script, sprintf(
            "<?php\nrequire %s;\n\\SugarCraft\\Crush\\Cli\\Bootstrap::chat(%s);\n",
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->project, true),
        ));

        $status = 0;
        $output = [];
        exec(sprintf(
            'HOME=%s timeout -s KILL %d %s %s >/dev/null 2>%s',
            escapeshellarg($this->home),
            self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($errFile),
        ), $output, $status);

        $this->assertTheChildWasNotKilledByItsBudget($status, 'the launch');

        return is_file($errFile) ? (string) file_get_contents($errFile) : '';
    }

    /**
     * A CHILD THAT NEVER RAN MUST NOT LOOK LIKE A CHILD THAT SAID NOTHING.
     *
     * Two of the tests above assert an ABSENCE from the child's stderr, and an
     * absence is what an empty file has. So before this arm existed, a child
     * killed by its budget — or one that died before it could write a byte —
     * left `testACleanLaunchIsSilent()` and `testACleanOneShotRunIsSilent()`
     * passing on a run that never happened. Measured both ways, PHP 8.3.6:
     * with the budget cut to a fraction of a second those two tests are GREEN
     * without this arm and RED with it.
     *
     * It refuses ONLY the budget's own kill. The launch child's exit status is
     * otherwise not this file's business — it is a TUI process in a pipe, and
     * demanding a particular status from it would be a second, unrelated claim.
     */
    private function assertTheChildWasNotKilledByItsBudget(int $status, string $which): void
    {
        $this->assertNotSame(self::KILLED_BY_THE_BUDGET, $status, \sprintf(
            '%s was SIGKILLed by its wall-clock budget of %d second(s), so every assertion '
            . 'below it is about a process that never finished. Either the child hung — which '
            . 'is what this budget is for, and the hang is the bug — or the budget is now too '
            . 'tight for this host. Raise CHILD_WALL_CLOCK_BUDGET_SECONDS knowingly, and note '
            . 'that it has a ceiling: past the per-test limit in phpunit.xml this file can no '
            . 'longer fail at all, it can only be aborted as risky.',
            $which,
            self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
        ));
    }

    /**
     * THE BUDGET HAS A CEILING AND THE CEILING LIVES IN ANOTHER FILE.
     *
     * `phpunit.xml` enforces a per-test limit through
     * `SebastianBergmann\Invoker\Invoker`, which is `pcntl_alarm()` plus a
     * handler that throws. When that fires the test is ABORTED, PHPUnit records
     * it RISKY rather than failed, and the assertions the arm would have made
     * are shed from the run's totals — so a budget at or above the per-test
     * limit converts every genuine hang in this file from a red into a moved
     * number. Reproduced on PHP 8.3.6 / PHPUnit 10.5.64 with a three-test probe
     * under a three-second limit: `Tests: 3, Assertions: 1, Risky: 2`, where
     * the two aborted tests would have made three assertions between them.
     *
     * ONE MORE THING WORTH KNOWING WHEN THIS EVER FIRES IN ANGER: the abort's
     * text quotes the CONFIGURED limit and not the elapsed time — `Invoker`
     * interpolates its own `$timeout` — so "aborted after 60 seconds" is a
     * statement about the configuration, not evidence that sixty seconds
     * passed. A stray `SIGALRM` from anywhere prints exactly the same sentence.
     */
    public function testTheChildBudgetStaysUnderThePerTestLimitPhpunitEnforces(): void
    {
        $limit = self::perTestLimitFromConfig(
            (string) file_get_contents(\dirname(__DIR__, 2) . '/phpunit.xml'),
        );

        // RULE: GO RED ON WHAT YOU CANNOT PARSE. A configuration this reader
        // cannot answer for is not a configuration with no limit in it.
        self::assertIsInt($limit, \is_string($limit) ? $limit : '');

        self::assertLessThanOrEqual(
            $limit - self::BUDGET_HEADROOM_SECONDS,
            self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
            \sprintf(
                'the child budget (%ds) has to stay at least %ds under the per-test limit '
                . 'phpunit.xml enforces (%ds). Closer than that and PHPUnit\'s own alarm can '
                . 'win the race, which turns a hung child from a red into a risky abort with '
                . 'its assertions shed. Lower the budget, or raise defaultTimeLimit first.',
                self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
                self::BUDGET_HEADROOM_SECONDS,
                $limit,
            ),
        );
    }

    /**
     * The per-test limit `phpunit.xml` actually imposes, or a sentence saying
     * why there is not one this reader can name.
     *
     * A PURE FUNCTION OVER THE TEXT, so the table below can push sources at it
     * whose answer is known — including the two shapes that mean "no limit"
     * and the three that mean "this reader is out of its depth". Answering
     * "no limit" for a source it merely failed to parse is the hole this shape
     * exists to avoid: it would read as permission for any budget at all.
     *
     * @return int|string seconds, or the reason there is no answer
     */
    private static function perTestLimitFromConfig(string $xml): int|string
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$root instanceof \SimpleXMLElement) {
            return 'phpunit.xml did not parse as XML, so no per-test limit could be read from it';
        }

        $enforce = $root['enforceTimeLimit'];
        if ($enforce === null || !\in_array(strtolower((string) $enforce), ['true', '1'], true)) {
            return 'phpunit.xml does not set enforceTimeLimit, so no per-test limit is imposed '
                . 'and this file\'s budget is the only bound on a hung child. That is a change '
                . 'to how a hang reports; re-argue this guard rather than deleting it.';
        }

        $limit = $root['defaultTimeLimit'];
        if ($limit === null) {
            return 'phpunit.xml enforces a time limit without setting defaultTimeLimit, so the '
                . 'limit is PHPUnit\'s own per-size default and depends on a size attribute '
                . 'this file does not declare';
        }

        $text = trim((string) $limit);
        if ($text === '' || !ctype_digit($text)) {
            return \sprintf('defaultTimeLimit is %s, which is not a whole number of seconds', var_export($text, true));
        }

        return (int) $text;
    }

    /**
     * KNOWN-ANSWER TABLE FOR {@see perTestLimitFromConfig()}, in every shape it
     * has to tell apart rather than in every value of one shape.
     *
     * The rows that must return a NUMBER are as important as the rows that must
     * not: a reader stuck at "cannot say" for the real file would leave the
     * guard above permanently red-on-the-wrong-thing, and a reader that
     * answered a number for a file with no limit in it would license any budget
     * at all. The attribute ORDER and the presence of a namespace vary between
     * rows on purpose — they are the shapes the real file has worn.
     *
     * @dataProvider perTestLimitCases
     */
    public function testThePerTestLimitReaderAnswersSourcesWhoseAnswerIsKnown(
        string $why,
        string $xml,
        int|string $expected,
    ): void {
        $answer = self::perTestLimitFromConfig($xml);

        \is_int($expected)
            ? self::assertSame($expected, $answer, $why)
            : self::assertIsString($answer, $why);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int|string}>
     */
    public static function perTestLimitCases(): iterable
    {
        $cannot = 'this reader has to say it cannot answer, not answer "no limit"';

        yield 'the shape this repository actually uses' => [
            'the reader cannot read the real file\'s shape, so the guard above never runs',
            '<phpunit bootstrap="tests/bootstrap.php" enforceTimeLimit="true" '
                . 'defaultTimeLimit="60" cacheDirectory=".phpunit.cache"/>',
            60,
        ];
        yield 'the limit is read whatever order the attributes come in' => [
            'the reader is keyed on attribute position rather than on attribute name',
            '<phpunit defaultTimeLimit="45" colors="true" enforceTimeLimit="true"/>',
            45,
        ];
        yield 'a namespaced root is still a root' => [
            'the xsi declaration the real file carries defeats the reader',
            '<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
                . 'xsi:noNamespaceSchemaLocation="x.xsd" enforceTimeLimit="true" defaultTimeLimit="30"/>',
            30,
        ];
        yield 'enforcement spelled as 1 rather than true' => [
            'the reader accepts only one spelling of a boolean XML attribute',
            '<phpunit enforceTimeLimit="1" defaultTimeLimit="12"/>',
            12,
        ];
        yield 'enforcement turned off means there is no limit to stay under' => [
            'a configuration that enforces nothing was reported as enforcing a number',
            '<phpunit enforceTimeLimit="false" defaultTimeLimit="60"/>',
            'no limit',
        ];
        yield 'enforcement absent means there is no limit to stay under' => [
            'a configuration that enforces nothing was reported as enforcing a number',
            '<phpunit defaultTimeLimit="60"/>',
            'no limit',
        ];
        yield 'enforcement on with no default is a limit this reader cannot name' => [
            $cannot,
            '<phpunit enforceTimeLimit="true"/>',
            'unknown',
        ];
        yield 'a non-numeric limit is unparseable, not absent' => [
            $cannot,
            '<phpunit enforceTimeLimit="true" defaultTimeLimit="sixty"/>',
            'unparseable',
        ];
        yield 'a fractional limit is unparseable, not truncated' => [
            'a fraction was silently truncated, which quietly changes the ceiling',
            '<phpunit enforceTimeLimit="true" defaultTimeLimit="60.5"/>',
            'unparseable',
        ];
        yield 'text that is not XML at all is unparseable, not absent' => [
            $cannot,
            'this is not xml',
            'not xml',
        ];
        yield 'an empty file is unparseable, not absent' => [
            $cannot,
            '',
            'empty',
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isLink() || !$entry->isDir() ? @unlink($entry->getPathname()) : @rmdir($entry->getPathname());
        }

        @rmdir($dir);
    }
}
