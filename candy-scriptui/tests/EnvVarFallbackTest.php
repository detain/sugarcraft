<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
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
        $this->originalEnvVerbose = getenv('CANDY_SCRIPTUI_VERBOSE') ?: '';
        $this->originalEnvForeground = getenv('CANDY_SCRIPTUI_FOREGROUND') ?: '';
        $this->originalEnvTimeout = getenv('CANDY_SCRIPTUI_TIMEOUT') ?: '';
    }

    protected function tearDown(): void
    {
        foreach (['CANDY_SCRIPTUI_VERBOSE', 'CANDY_SCRIPTUI_FOREGROUND', 'CANDY_SCRIPTUI_TIMEOUT'] as $var) {
            $orig = match ($var) {
                'CANDY_SCRIPTUI_VERBOSE' => $this->originalEnvVerbose,
                'CANDY_SCRIPTUI_FOREGROUND' => $this->originalEnvForeground,
                'CANDY_SCRIPTUI_TIMEOUT' => $this->originalEnvTimeout,
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

    /**
     * scriptui-39 [gate G6 default leg]: the manifest walk is identity-gated
     * to `sugarcraft/candy-scriptui`, so a source checkout of the monorepo
     * answers with THIS package's manifest (no version field → 0.0.0), never
     * with the host root's release version. Re-pinned from the old
     * "parses monorepo root" contract, which was the bug being cured.
     */
    public function testVersionFromComposerIsBoundedToThisPackage(): void
    {
        $app = new Application();
        $version = $app->versionFromComposer();

        $packageComposer = dirname(__DIR__) . '/composer.json';
        $this->assertFileExists($packageComposer);
        $json = json_decode(file_get_contents($packageComposer), true);
        $this->assertSame('sugarcraft/candy-scriptui', $json['name']);
        $expectedVersion = is_array($json) ? ($json['version'] ?? '0.0.0') : '0.0.0';
        $this->assertSame($expectedVersion, $version);

        // The host root's version must never surface as the tool's own.
        $rootJson = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        if (is_array($rootJson) && isset($rootJson['version'])) {
            $this->assertNotSame($rootJson['version'], $version);
        }

        // The walk stopped at the package manifest, not beyond it.
        $method = new \ReflectionMethod(Application::class, 'findRootComposerJson');
        $method->setAccessible(true);
        $found = $method->invoke(null);
        $this->assertIsArray($found);
        $this->assertSame('sugarcraft/candy-scriptui', $found['name']);
    }

    /**
     * Tests the CANDY_SCRIPTUI_* env-var fallback path through Application::run()
     * and doRun(). This is the exact scenario that was throwing "option does
     * not exist" before the fix.
     */
    public function testEnvVarFallbackIsAppliedThroughRun(): void
    {
        putenv('CANDY_SCRIPTUI_FOREGROUND=#ff0000');
        try {
            $app = new Application();
            $out = new BufferedOutput();
            $status = $app->run(new ArgvInput(['candy-scriptui', 'style', 'hello']), $out);

            $this->assertSame(0, $status);
            $display = $out->fetch();
            // Red SGR: \x1b[38;2;255;0;0m
            $this->assertStringContainsString("\x1b[38;2;255;0;0m", $display);
            $this->assertStringContainsString('hello', $display);
        } finally {
            putenv('CANDY_SCRIPTUI_FOREGROUND');
        }
    }

    /**
     * Tests that an explicit --foreground flag takes precedence over
     * CANDY_SCRIPTUI_FOREGROUND, exercising hasParameterOption() in doRun().
     */
    public function testExplicitFlagBeatsEnvVar(): void
    {
        putenv('CANDY_SCRIPTUI_FOREGROUND=#ff0000');
        try {
            $app = new Application();
            $out = new BufferedOutput();
            $status = $app->run(
                new ArgvInput(['candy-scriptui', 'style', '--foreground=#0000ff', 'hello']),
                $out,
            );

            $this->assertSame(0, $status);
            $display = $out->fetch();
            // Blue SGR: \x1b[38;2;0;0;255m — NOT red
            $this->assertStringContainsString("\x1b[38;2;0;0;255m", $display);
            $this->assertStringNotContainsString("\x1b[38;2;255;0;0m", $display);
            $this->assertStringContainsString('hello', $display);
        } finally {
            putenv('CANDY_SCRIPTUI_FOREGROUND');
        }
    }

    /**
     * M1 (review 2026-11-02): VALUE_NONE options get no value for a re-bind
     * to restore, so setOption(true) was wiped exactly like value options —
     * every CANDY_SCRIPTUI_<FLAG> was dead in the bin. The README's promise
     * that the two invocations are the same is the spec: the env-backed run
     * must produce byte-identical output to the explicit-flag run.
     */
    public function testEnvVarBackedFlagEqualsExplicitFlag(): void
    {
        putenv('CANDY_SCRIPTUI_BOLD=1');
        try {
            $envOut = new BufferedOutput();
            $this->assertSame(0, (new Application())->run(new ArgvInput(['candy-scriptui', 'style', 'hi']), $envOut));

            $flagOut = new BufferedOutput();
            $this->assertSame(0, (new Application())->run(new ArgvInput(['candy-scriptui', 'style', '--bold', 'hi']), $flagOut));

            $envDisplay = $envOut->fetch();
            $this->assertSame($flagOut->fetch(), $envDisplay, 'env-backed --bold must equal explicit --bold');
            $this->assertStringContainsString("\x1b[1m", $envDisplay, 'guard against both sides silently losing bold');
        } finally {
            putenv('CANDY_SCRIPTUI_BOLD');
        }
    }

    public function testEnvVarFalseyValueLeavesFlagOff(): void
    {
        putenv('CANDY_SCRIPTUI_BOLD=0');
        try {
            $envOut = new BufferedOutput();
            $this->assertSame(0, (new Application())->run(new ArgvInput(['candy-scriptui', 'style', 'hi']), $envOut));

            $plainOut = new BufferedOutput();
            $this->assertSame(0, (new Application())->run(new ArgvInput(['candy-scriptui', 'style', 'hi']), $plainOut));

            $envDisplay = $envOut->fetch();
            $this->assertSame($plainOut->fetch(), $envDisplay);
            $this->assertStringNotContainsString("\x1b[1m", $envDisplay);
        } finally {
            putenv('CANDY_SCRIPTUI_BOLD');
        }
    }

    public function testEnvVarAndExplicitFlagTogetherStillBold(): void
    {
        putenv('CANDY_SCRIPTUI_BOLD=yes');
        try {
            $out = new BufferedOutput();
            $this->assertSame(0, (new Application())->run(new ArgvInput(['candy-scriptui', 'style', '--bold', 'hi']), $out));
            $this->assertStringContainsString("\x1b[1m", $out->fetch());
        } finally {
            putenv('CANDY_SCRIPTUI_BOLD');
        }
    }

    /**
     * M11 (cluster 11): the env fallback is an ALLOW-LIST. The universal
     * mapping used to let CANDY_SCRIPTUI_ALLOW_ENV='*' arm `format
     * --type template` with whole-environment exfiltration — measured
     * `secret=hunter2` landing on stdout with zero warning.
     */
    public function testAllowEnvIsNotSourcedFromTheEnvironment(): void
    {
        $canary = 'B8-EXFIL-CANARY-' . bin2hex(random_bytes(4));
        $secret = 'hunter2-' . bin2hex(random_bytes(4));
        $template = tempnam(sys_get_temp_dir(), 'sui-b8-');
        self::assertIsString($template);
        file_put_contents($template, 'V={{' . $canary . '}}END');
        putenv("CANDY_SCRIPTUI_ALLOW_ENV=*");
        putenv("{$canary}={$secret}");
        try {
            $out = new BufferedOutput();
            $status = (new Application())->run(
                new ArgvInput(['candy-scriptui', 'format', $template, '--type=template']),
                $out,
            );
            $display = $out->fetch();
            $this->assertSame(0, $status);
            $this->assertStringNotContainsString($secret, $display, 'env-supplied allow-env must not expand anything');
            $this->assertStringContainsString('V=', $display);
        } finally {
            putenv('CANDY_SCRIPTUI_ALLOW_ENV');
            putenv($canary);
            @unlink($template);
        }
    }

    /**
     * M11: CANDY_SCRIPTUI_FILE used to retarget sinks across commands —
     * one unscoped variable hit log --file, format's input file argument,
     * and anything else named --file. The allow-list keeps it CLI-only.
     */
    public function testFileSinkIsNotSourcedFromTheEnvironment(): void
    {
        $sink = sys_get_temp_dir() . '/sui-b8-sink-' . bin2hex(random_bytes(4)) . '.log';
        putenv('CANDY_SCRIPTUI_FILE=' . $sink);
        try {
            $out = new BufferedOutput();
            $status = (new Application())->run(new ArgvInput(['candy-scriptui', 'log', 'hello-b8']), $out);
            $this->assertSame(0, $status);
            $this->assertStringContainsString('hello-b8', $out->fetch(), 'message must ride stdout when env tries to redirect it');
            $this->assertFileDoesNotExist($sink, 'CANDY_SCRIPTUI_FILE must not retarget the log sink');
        } finally {
            putenv('CANDY_SCRIPTUI_FILE');
            @unlink($sink);
        }
    }

    /**
     * M11 roster pin: the capability/answer/sink names must stay OFF the
     * allow-list (the two behavioural tests above cover their commands;
     * this pins the whole roster against future widening-by-accident).
     */
    public function testAllowListExcludesEverySecurityRelevantName(): void
    {
        foreach (['allow-env', 'default', 'file', 'value', 'selected', 'out', 'type', 'shell', 'password'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                Application::ENV_FALLBACK_OPTIONS,
                "'{$forbidden}' must never be env-sourceable",
            );
        }
    }
}
