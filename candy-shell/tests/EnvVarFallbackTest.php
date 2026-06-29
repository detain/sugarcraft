<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class EnvVarFallbackTest extends TestCase
{
    private string $originalEnvVerbose;
    private string $originalEnvForeground;
    private string $originalEnvTimeout;

    protected function setUp(): void
    {
        $this->originalEnvVerbose = getenv('CANDYSHELL_VERBOSE') ?: '';
        $this->originalEnvForeground = getenv('CANDYSHELL_FOREGROUND') ?: '';
        $this->originalEnvTimeout = getenv('CANDYSHELL_TIMEOUT') ?: '';
    }

    protected function tearDown(): void
    {
        foreach (['CANDYSHELL_VERBOSE', 'CANDYSHELL_FOREGROUND', 'CANDYSHELL_TIMEOUT'] as $var) {
            $orig = match ($var) {
                'CANDYSHELL_VERBOSE' => $this->originalEnvVerbose,
                'CANDYSHELL_FOREGROUND' => $this->originalEnvForeground,
                'CANDYSHELL_TIMEOUT' => $this->originalEnvTimeout,
                default => '',
            };
            if ($orig !== '') {
                putenv("{$var}={$orig}");
            } else {
                putenv($var);
            }
        }
    }

    public function testExplicitOptionIsUsedWhenProvided(): void
    {
        $app = new Application();
        $command = $app->find('style');

        $tester = new CommandTester($command);
        $tester->execute(['--foreground' => '#0000ff', 'text' => ['hello']], ['decorated' => false]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString("\x1b[38;2;0;0;255m", $output);
        $this->assertStringContainsString('hello', $output);
    }

    public function testVersionFromComposerReturnsString(): void
    {
        $app = new Application();
        $version = $app->versionFromComposer();

        $this->assertIsString($version);
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
    }

    public function testVersionFromComposerParsesMonorepoRoot(): void
    {
        $app = new Application();
        $version = $app->versionFromComposer();

        $rootComposer = dirname(dirname(__DIR__)) . '/composer.json';
        $this->assertFileExists($rootComposer);
        $json = json_decode(file_get_contents($rootComposer), true);
        $expectedVersion = is_array($json) ? ($json['version'] ?? '0.0.0') : '0.0.0';

        $this->assertSame($expectedVersion, $version);
    }

    /**
     * Tests the CANDYSHELL_* env-var fallback path through Application::run()
     * and doRun(). This is the exact scenario that was throwing "option does
     * not exist" before the fix.
     */
    public function testEnvVarFallbackIsAppliedThroughRun(): void
    {
        putenv('CANDYSHELL_FOREGROUND=#ff0000');
        try {
            $app = new Application();
            $out = new BufferedOutput();
            $status = $app->run(new ArgvInput(['candyshell', 'style', 'hello']), $out);

            $this->assertSame(0, $status);
            $display = $out->fetch();
            // Red SGR: \x1b[38;2;255;0;0m
            $this->assertStringContainsString("\x1b[38;2;255;0;0m", $display);
            $this->assertStringContainsString('hello', $display);
        } finally {
            putenv('CANDYSHELL_FOREGROUND');
        }
    }

    /**
     * Tests that an explicit --foreground flag takes precedence over
     * CANDYSHELL_FOREGROUND, exercising hasParameterOption() in doRun().
     */
    public function testExplicitFlagBeatsEnvVar(): void
    {
        putenv('CANDYSHELL_FOREGROUND=#ff0000');
        try {
            $app = new Application();
            $out = new BufferedOutput();
            $status = $app->run(
                new ArgvInput(['candyshell', 'style', '--foreground=#0000ff', 'hello']),
                $out,
            );

            $this->assertSame(0, $status);
            $display = $out->fetch();
            // Blue SGR: \x1b[38;2;0;0;255m — NOT red
            $this->assertStringContainsString("\x1b[38;2;0;0;255m", $display);
            $this->assertStringNotContainsString("\x1b[38;2;255;0;0m", $display);
            $this->assertStringContainsString('hello', $display);
        } finally {
            putenv('CANDYSHELL_FOREGROUND');
        }
    }
}
