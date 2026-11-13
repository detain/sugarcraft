<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests\Driver;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\SignalResizeDriver;

/**
 * Guard pins for SignalResizeDriver's tput capability (shell-injection guard).
 *
 * Both polarities: well-formed terminfo names reach the shell ONLY as a
 * quoted, exact command shape; injection-shaped names are refused at the
 * guard with a typed refusal and cost zero shell-outs. The command string
 * is captured through the driver's internal executor seam — no real
 * subprocess ever runs here.
 *
 * NOTE: construction is side-effect free (only arm() probes dimensions),
 * so the command log starts empty; the drain in makeDriver() is kept as a
 * guard in case a future constructor ever shells out before a test runs.
 */
final class SignalResizeDriverCapabilityGateTest extends TestCase
{
    /**
     * Build a driver whose runner records commands into $recorder and
     * answers $runnerOutput; the log starts drained of construction probes.
     *
     * @param \ArrayObject<int, string>|null $recorder
     */
    private function makeDriver(?object &$recorder, string $runnerOutput = "80\n"): SignalResizeDriver
    {
        $recorder = new \ArrayObject();
        $driver = new SignalResizeDriver(
            static function (string $command) use ($recorder, $runnerOutput): string {
                $recorder->append($command);

                return $runnerOutput;
            }
        );
        $recorder->exchangeArray([]);

        return $driver;
    }

    /**
     * @param \ArrayObject<int, string> $recorder
     *
     * @return list<string>
     */
    private function commands(\ArrayObject $recorder): array
    {
        return $recorder->getArrayCopy();
    }

    /**
     * Invoke the private static guard+builder directly.
     */
    private function build(string $capability): string
    {
        $method = new \ReflectionMethod(SignalResizeDriver::class, 'tputCommand');

        return $method->invoke(null, $capability);
    }

    public function testLegitCapabilitiesProduceExactlyQuotedCommands(): void
    {
        $driver = $this->makeDriver($recorder);
        $probeTput = new \ReflectionMethod($driver, 'probeTput');

        $colsValue = $probeTput->invoke($driver, 'cols');
        // Command-shape pin: validated name, escapeshellarg'd, redirect kept.
        $this->assertSame(["tput 'cols' 2>/dev/null"], $this->commands($recorder));
        $this->assertSame(80, $colsValue);

        $driver = $this->makeDriver($recorder);
        $probeTput = new \ReflectionMethod($driver, 'probeTput');

        $linesValue = $probeTput->invoke($driver, 'lines');
        $this->assertSame(["tput 'lines' 2>/dev/null"], $this->commands($recorder));
        $this->assertSame(80, $linesValue);
    }

    public function testNumericParseBehaviourSurvivesTheGuard(): void
    {
        $driver = $this->makeDriver($recorder, "132\n");
        $value = (new \ReflectionMethod($driver, 'probeTput'))->invoke($driver, 'cols');

        $this->assertCount(1, $this->commands($recorder));
        $this->assertSame(132, $value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function injectionShapedNames(): array
    {
        return [
            'chained command' => ['ls; rm'],
            'command substitution' => ['$(id)'],
            'backtick substitution' => ['`id`'],
            'option smuggling' => ['--version--'],
            'leading dash option' => ['-cols'],
            'embedded space' => ['x y'],
            'uppercase' => ['COLS'],
            'capital initial' => ['Cols'],
            'empty string' => [''],
            'digit initial' => ['5cols'],
            'too long' => ['abcdef'],
            'punctuation' => ['cols!'],
            'newline split' => ["cols\nevil"],
            'semicolon tail' => ['cols;id'],
        ];
    }

    /**
     * @dataProvider injectionShapedNames
     */
    public function testInjectionShapedNamesAreRefusedBeforeAnyShellOut(string $capability): void
    {
        $driver = $this->makeDriver($recorder);
        $probeTput = new \ReflectionMethod($driver, 'probeTput');

        try {
            $probeTput->invoke($driver, $capability);
            $this->fail('expected InvalidArgumentException for ' . var_export($capability, true));
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('refused', $exception->getMessage());
        }

        $this->assertSame([], $this->commands($recorder), 'a refused capability must never reach the shell');
    }

    /**
     * The refusal is diagnosable: it names the offender and the bound.
     *
     * @dataProvider injectionShapedNames
     */
    public function testRefusalNamesTheRejectedCapability(string $capability): void
    {
        try {
            $this->build($capability);
            $this->fail('expected InvalidArgumentException for ' . var_export($capability, true));
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('refused', $exception->getMessage());
            $this->assertStringContainsString('terminfo capability', $exception->getMessage());
        }
    }

    /**
     * Guard boundary: letter-initial, 1-5 lowercase alnum, exactly.
     */
    public function testAllowlistBoundaryAcceptsWellFormedTerminfoNames(): void
    {
        $this->assertSame("tput 'c' 2>/dev/null", $this->build('c'));
        $this->assertSame("tput 'cols' 2>/dev/null", $this->build('cols'));
        $this->assertSame("tput 'x9' 2>/dev/null", $this->build('x9'));
        $this->assertSame("tput 'abcde' 2>/dev/null", $this->build('abcde'));
    }
}
