<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use SugarCraft\MdView\MdViewModel;
use SugarCraft\MdView\ReloadTickMsg;
use SugarCraft\MdView\RenderCommand;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use SugarCraft\Core\I18n\T;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RenderCommandTest extends TestCase
{
    /**
     * Every environment variable the color/theme chain consults. The auto
     * theme is decided from globals, so the suite pins them class-wide:
     * a developer box exporting NO_COLOR must not flip these answers.
     */
    private const COLOR_ENV_KEYS = ['NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR', 'TERM'];

    /** @var array<string,string|false> values as found before setUp cleared them */
    private array $savedColorEnv = [];

    /** locale as found before setUp pinned it to 'en' */
    private string $savedLocale = 'en';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::COLOR_ENV_KEYS as $key) {
            $this->savedColorEnv[$key] = getenv($key);
            putenv($key); // unset
        }
        // KM6: the locale is now adopted from the environment at boot, so
        // error-message pins must pin the locale too — English here, whatever
        // the developer has exported elsewhere.
        $this->savedLocale = T::locale();
        T::setLocale('en');
    }

    /**
     * @param non-empty-string $method
     *
     * @return mixed the static method's return value
     */
    private function invokeStatic(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(RenderCommand::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    public function testPagerDocumentNameFromArgvIsSanitizedBeforeTheStatusHeader(): void
    {
        $forged = "re\x1b]8;;http://evil\x07port.md\n\x1b[31mnext";

        /** @var MdViewModel $model */
        $model = $this->invokeStatic('buildPagerModel', ['body', 80, 24, $forged]);

        $name = $model->documentName;
        $this->assertIsString($name);
        $this->assertStringNotContainsString("\x1b", $name, 'raw ESC bytes must never reach the pager status header');
        $this->assertStringNotContainsString("\x07", $name, 'BEL must not survive the document-name sink');
        $this->assertStringNotContainsString("\n", $name, 'a newline in a file name cannot split the header row');
        $this->assertStringContainsString('[31m', $name, 'printable survivors stay visible instead of vanishing');
        $this->assertStringStartsWith('re', $name);
        $this->assertStringNotContainsString("\x1b]8;;", $model->view(), 'forged OSC-8 envelope must not ride the view');
    }

    public function testPickThemeAnsi(): void
    {
        $theme = RenderCommand::pickTheme('ansi');
        $this->assertInstanceOf(Theme::class, $theme);
    }

    public function testPickThemePlain(): void
    {
        $theme = RenderCommand::pickTheme('plain');
        $this->assertSame('plain', $theme->paragraph->render('plain'));
    }

    public function testPickThemeCaseInsensitive(): void
    {
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('ANSI'));
    }

    public function testPickThemeRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RenderCommand::pickTheme('mystery');
    }

    public function testLoadInputReadsFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdview-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "# Hello");
        try {
            $this->assertSame("# Hello", RenderCommand::loadInput($tmp));
        } finally {
            unlink($tmp);
        }
    }

    public function testLoadInputMissingFileReturnsNull(): void
    {
        $this->assertNull(RenderCommand::loadInput('/no/such/path/candy-mdview-test.md'));
    }

    public function testPickThemeDarkLightDraculaTokyoNightPink(): void
    {
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('dark'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('light'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('dracula'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('tokyo-night'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('tokyonight'));
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('pink'));
        // Underscores accepted as separators.
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme('tokyo_night'));
    }

    public function testPickThemeNotty(): void
    {
        // Notty is a no-style fallback (matches plain visually).
        $theme = RenderCommand::pickTheme('notty');
        $this->assertSame('plain', $theme->paragraph->render('plain'));
    }

    public function testPickThemeEmptyDefaultsToAnsi(): void
    {
        // The CLI passes the --theme default of 'ansi', but a direct
        // empty-string call should still produce a usable Theme.
        $this->assertInstanceOf(Theme::class, RenderCommand::pickTheme(''));
    }

    // --- execute() tests ---

    private function invokeExecute(RenderCommand $command, InputInterface $input, OutputInterface $output): int
    {
        $method = new ReflectionMethod($command, 'execute');
        $method->setAccessible(true);
        return $method->invoke($command, $input, $output);
    }

    /**
     * Build a print-mode execute() run over $content and capture the single
     * raw-write payload the command emits.
     *
     * @return array{0:int,1:string} exit code + written bytes
     */
    private function runPrint(string $content, array $optionMap = [], array $argumentMap = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdview-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $content);
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->willReturnCallback(
                static fn(string $name): mixed => $argumentMap[$name] ?? ($name === 'file' ? $tmp : null)
            );
            $input->method('getOption')->willReturnMap($optionMap);

            $output = $this->createMock(OutputInterface::class);
            $payload = null;
            $newline = null;
            $type = null;
            $output->method('write')->willReturnCallback(
                static function ($messages, bool $nl, int $t) use (&$payload, &$newline, &$type): void {
                    $payload  = (string) $messages;
                    $newline  = $nl;
                    $type     = $t;
                }
            );

            $result = $this->invokeExecute(new RenderCommand(), $input, $output);
            $this->assertNotNull($payload, 'print mode must emit exactly one write()');
            return [$result, $payload];
        } finally {
            unlink($tmp);
        }
    }

    /** @return array{0:int,1:string,2:bool,3:int} rc + payload + newline flag + verbosity type */
    private function runPrintCapturingArgs(string $content, array $optionMap = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdview-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $content);
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->willReturnCallback(
                static fn(string $name): mixed => $name === 'file' ? $tmp : null
            );
            $input->method('getOption')->willReturnMap($optionMap);

            $output = $this->createMock(OutputInterface::class);
            $captured = [];
            $output->method('write')->willReturnCallback(
                static function ($messages, bool $nl, int $t) use (&$captured): void {
                    $captured = [(string) $messages, $nl, $t];
                }
            );

            $result = $this->invokeExecute(new RenderCommand(), $input, $output);
            $this->assertCount(3, $captured);
            return [$result, ...$captured];
        } finally {
            unlink($tmp);
        }
    }

    public function testExecuteWithNoInputOnTerminalIsUsageError(): void
    {
        // KM5: a tty-stdin run used to print the one-word "no input" to
        // STDOUT with FAILURE — an error in the data channel and a code that
        // meant four different faults at once. It is a named usage fault now:
        // STDERR-routed through writeError, INVALID (2). The seam keeps the
        // throw before any real STDIN read, so the test never blocks.
        RenderCommand::setStdinTtyCallback(fn () => true);

        $input = $this->createMock(InputInterface::class);
        $input->method('getArgument')->with('file')->willReturn(null);
        $input->method('getOption')->willReturnMap([
            ['theme-config', ''],
            ['style', null],
            ['theme', 'ansi'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);

        $captured = '';
        $output   = $this->createMock(OutputInterface::class);
        $output->method('writeln')->willReturnCallback(
            static function ($messages) use (&$captured): void {
                $captured .= (string) $messages;
            }
        );

        $command = new RenderCommand();
        $result  = $this->invokeExecute($command, $input, $output);
        $this->assertSame(Command::INVALID, $result);
        $this->assertStringContainsString('no input:', $captured);
        $this->assertStringContainsString('file argument', $captured);
    }

    public function testExecuteWithValidInputNoPagerReturnsSuccess(): void
    {
        // Piped shape (terminal check false): the implicit chain picks the
        // byte-plain theme and the payload still carries the document text.
        RenderCommand::setTerminalCheckCallback(fn () => false);
        [$result, $payload] = $this->runPrint("# Hello\n\nWorld", [
            ['theme-config', ''],
            ['style', null],
            ['theme', null],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString('Hello', $payload);
        $this->assertStringNotContainsString("\x1b[", $payload);
    }

    public function testExecuteWithStyleOptionUsesStyleAsTheme(): void
    {
        [$result, $payload] = $this->runPrint("plain text", [
            ['theme-config', ''],
            ['style', 'plain'],
            ['theme', null],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString('plain text', $payload);
    }

    public function testExecuteWithThemeConfigOverridesTheme(): void
    {
        // The original fixture used {"paragraph":{"fg":…,"bg":…}} — DEAD
        // keys the flat schema never consumes (canonical spelling is
        // `foreground`) — so SUCCESS + 'test' passed under any theme and the
        // name lied. This version has discriminating power: the same input
        // under a live-key red/bold paragraph config must render differing
        // bytes versus the implicit plain pipe theme, with real SGR on the
        // configured side and none on the control.
        $configTmp = tempnam(sys_get_temp_dir(), 'mdview-config-');
        $this->assertNotFalse($configTmp);
        file_put_contents($configTmp, '{"paragraph":{"foreground":"#ff0000","bold":true}}');
        try {
            [$result, $payload] = $this->runPrint("test paragraph", $this->optionMap([
                'theme-config' => $configTmp,
            ]));
            $this->assertSame(Command::SUCCESS, $result);
            $this->assertStringContainsString('test paragraph', $payload);

            [$controlResult, $controlPayload] = $this->runPrint("test paragraph", $this->optionMap());
            $this->assertSame(Command::SUCCESS, $controlResult);

            $this->assertNotSame($controlPayload, $payload, 'the config must change rendered bytes');
            $this->assertStringContainsString("\x1b[", $payload, 'styled theme output carries SGR');
            $this->assertStringNotContainsString("\x1b[", $controlPayload, 'implicit pipe theme is plain');
        } finally {
            unlink($configTmp);
        }
    }

    public function testBinaryControlRatioBoundaryIsPinned(): void
    {
        // Finding 023: every shipped binary pin routes through NUL, so the
        // documented BINARY_CONTROL_RATIO = 0.05 branch was never exercised.
        // Two NUL-free cases pin it at today's exact semantics: 50 control
        // bytes in 1000 is AT the ratio (not above) and passes; 51 is over
        // and refuses with the localized message.
        $atRatio = str_repeat('a', 950) . str_repeat("\x01", 50);
        [$passRc] = $this->runPrint($atRatio, $this->optionMap());
        $this->assertSame(Command::SUCCESS, $passRc, 'exactly-at-ratio text must render');

        $overRatio = str_repeat('a', 949) . str_repeat("\x01", 51);
        [$failRc, $msg] = $this->runUsageRun($overRatio, $this->optionMap());
        $this->assertSame(Command::INVALID, $failRc, 'one byte over the ratio must refuse');
        $this->assertStringContainsString('not a text file', $msg);
    }

    public function testFileLaneEnforcesTheInputSizeCap(): void
    {
        // Finding 024: the 64 MiB cap's enforcement lane had zero coverage —
        // only its message formatting was pinned via Lang interpolation, so
        // dropping the pre-read filesize() guard kept the suite green.
        // A sparse ftruncate is metadata-only (instant), yet filesize()
        // answers the over-cap length the guard reads.
        $path = sys_get_temp_dir() . '/mdview-overcap-' . uniqid() . '.md';
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        $this->assertTrue(ftruncate($handle, 67_108_865));
        fclose($handle);
        try {
            [$rc, $msg] = $this->runUsageRun(null, $this->optionMap(), ['file' => $path]);
            $this->assertSame(Command::INVALID, $rc, 'a file past MAX_INPUT_BYTES must refuse on the file lane');
            $this->assertStringContainsString('67108864', $msg);
            $this->assertStringContainsString('exceeds', $msg);
        } finally {
            unlink($path);
        }
    }

    public function testExecuteWithWidthOptionEnablesWordWrap(): void
    {
        RenderCommand::setTerminalCheckCallback(fn () => false);
        [$result, $payload] = $this->runPrint(str_repeat("word ", 50), [
            ['theme-config', ''],
            ['style', null],
            ['theme', null],
            ['width', 40],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        // A 250-column paragraph must come back as several wrapped rows.
        $this->assertGreaterThanOrEqual(3, substr_count($payload, "\n"));
        foreach (explode("\n", $payload) as $row) {
            $this->assertLessThanOrEqual(40, strlen(rtrim($row)), 'no wrapped row exceeds the width budget');
        }
    }

    public function testExecuteWithNoHyperlinksDisablesHyperlinks(): void
    {
        // Explicit dark theme on a terminal: the OSC 8 envelope is that
        // theme's default, and --no-hyperlinks must strip it while keeping
        // the link text and the visible (url) tail.
        RenderCommand::setTerminalCheckCallback(fn () => true);
        [$result, $payload] = $this->runPrint("[link](https://example.com)", [
            ['theme-config', ''],
            ['style', null],
            ['theme', 'dark'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', true],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringNotContainsString("\x1b]8;;", $payload);
        $this->assertStringContainsString('(https://example.com)', $payload);
    }

    public function testHyperlinkEnvelopesAreTerminalOnly(): void
    {
        // The pipe face of the OSC 8 etiquette fix: WITHOUT any flag, a
        // non-terminal stream still gets no clickable envelopes (the gate
        // is capability, not just the opt-out).
        RenderCommand::setTerminalCheckCallback(fn () => false);
        [$result, $payload] = $this->runPrint("[link](https://example.com)", [
            ['theme-config', ''],
            ['style', null],
            ['theme', 'dark'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringNotContainsString("\x1b]8;;", $payload);
        $this->assertStringContainsString('(https://example.com)', $payload);
    }

    public function testHyperlinkEnvelopesAppearOnTerminals(): void
    {
        // The negative control: same document, terminal capability TRUE and
        // no opt-out, and the envelope MUST be emitted. Without this pair
        // the pipe suppression above could be satisfied by never hyperlinking.
        RenderCommand::setTerminalCheckCallback(fn () => true);
        [$result, $payload] = $this->runPrint("[link](https://example.com)", [
            ['theme-config', ''],
            ['style', null],
            ['theme', 'dark'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString("\x1b]8;;https://example.com", $payload);
    }

    public function testExecuteWithDarkTheme(): void
    {
        RenderCommand::setTerminalCheckCallback(fn () => true);
        [$result, $payload] = $this->runPrint("# Dark Theme Test", [
            ['theme-config', ''],
            ['style', null],
            ['theme', 'dark'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString('Dark Theme Test', $payload);
        $this->assertStringContainsString("\x1b[", $payload, 'a color theme must decorate');
    }

    public function testExecuteWithTokyoNightTheme(): void
    {
        RenderCommand::setTerminalCheckCallback(fn () => true);
        [$result, $payload] = $this->runPrint("# Tokyo Night Theme Test", [
            ['theme-config', ''],
            ['style', null],
            ['theme', 'tokyo-night'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString('Tokyo Night Theme Test', $payload);
    }

    protected function tearDown(): void
    {
        RenderCommand::setColorProbeCallback(null);
        RenderCommand::setTerminalCheckCallback(null);
        RenderCommand::setStdinTtyCallback(null);
        T::setLocale($this->savedLocale);
        foreach ($this->savedColorEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        parent::tearDown();
    }

    public function testTerminalSupportsColorWhenProbeSaysColorCapable(): void
    {
        RenderCommand::setColorProbeCallback(fn () => true);
        $method = new \ReflectionMethod(RenderCommand::class, 'terminalSupportsColor');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke(null));
    }

    public function testTerminalSupportsColorWhenProbeSaysNoColor(): void
    {
        RenderCommand::setColorProbeCallback(fn () => false);
        $method = new \ReflectionMethod(RenderCommand::class, 'terminalSupportsColor');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke(null));
    }

    public function testTerminalSupportsColorGracefulDegradationOnProbeFailure(): void
    {
        RenderCommand::setColorProbeCallback(fn () => throw new \RuntimeException('probe failed'));
        $method = new \ReflectionMethod(RenderCommand::class, 'terminalSupportsColor');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke(null));
    }

    public function testTerminalSupportsColorReturnsFalseWhenNoColorEnvSet(): void
    {
        $original = getenv('NO_COLOR');
        try {
            putenv('NO_COLOR=1');
            $method = new \ReflectionMethod(RenderCommand::class, 'terminalSupportsColor');
            $method->setAccessible(true);
            $this->assertFalse($method->invoke(null));
        } finally {
            if ($original === false) {
                putenv('NO_COLOR');
            } else {
                putenv("NO_COLOR=$original");
            }
        }
    }

    public function testTerminalSupportsColorReturnsFalseWhenClicolorZero(): void
    {
        $original = getenv('CLICOLOR');
        try {
            putenv('CLICOLOR=0');
            $method = new \ReflectionMethod(RenderCommand::class, 'terminalSupportsColor');
            $method->setAccessible(true);
            $this->assertFalse($method->invoke(null));
        } finally {
            if ($original === false) {
                putenv('CLICOLOR');
            } else {
                putenv("CLICOLOR=$original");
            }
        }
    }

    public function testExecuteAutoSelectsAsciiEmphasisOnColorlessTerminal(): void
    {
        // KM10 keystone: color-off must not mean structure-off. A terminal
        // whose probe says "no color" gets Theme::ascii() — bold/italic/
        // underline survive. The shipped chain downgraded this path to
        // notty(), which sugar-markdown defines as plain(), so ALL emphasis
        // died. Red pre-fix: zero SGR bytes in the payload.
        RenderCommand::setTerminalCheckCallback(fn () => true);
        RenderCommand::setColorProbeCallback(fn () => false);
        [$result, $payload] = $this->runPrint("# Hello\n\nWorld **bold** and *em*.", [
            ['theme-config', ''],
            ['style', null],
            ['theme', null], // omitted option — implicit chain decides
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString("\x1b[1m", $payload, 'bold must survive color-off');
        $this->assertStringContainsString("\x1b[3m", $payload, 'italic must survive color-off');
        $this->assertDoesNotMatchRegularExpression(
            '/\x1b\[(?:3[0-9]|4[0-9]|9[0-9]|38;|48;)[0-9;]*m/',
            $payload,
            'the colorless tier must emit no color SGR'
        );
    }

    public function testExecuteRespectsExplicitThemeEvenWhenNoColor(): void
    {
        // Explicit choices are honored verbatim (they include notty/plain by
        // name, so "color off" cannot downgrade a named theme).
        RenderCommand::setTerminalCheckCallback(fn () => true);
        RenderCommand::setColorProbeCallback(fn () => false);
        [$result, $payload] = $this->runPrint("# Hello\n\nWorld", [
            ['theme-config', ''],
            ['style', null],
            ['theme', 'dracula'], // explicit theme choice
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertMatchesRegularExpression('/\x1b\[(?:38;2;|38;5;|48;)/', $payload, 'dracula must paint');
    }

    // --- color-chain keystones (KC1 / KC2 / KM8) ---------------------------

    public function testKeystoneUnsetNoColorStillColors(): void
    {
        // THE KC1 keystone. With NO_COLOR absent from the environment (setUp
        // guarantees that), the shipped `getenv('NO_COLOR') !== ''` compared
        // FALSE !== '' — true — and forced every implicit run monochrome.
        // Unset must now be "no opinion": the probe answers, TERM says color.
        putenv('TERM=xterm-256color');
        $this->assertTrue($this->invokeStatic('terminalSupportsColor'));

        RenderCommand::setTerminalCheckCallback(fn () => true);
        [$result, $payload] = $this->runPrint("# Hello\n\nWorld", [
            ['theme-config', ''],
            ['style', null],
            ['theme', null],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString("\x1b[38;2;", $payload, 'default on a color terminal must be the ansi theme');
    }

    public function testProbeAnswersNoColorForDumbTerminal(): void
    {
        putenv('TERM=dumb');
        $this->assertFalse($this->invokeStatic('terminalSupportsColor'));
    }

    public function testProbeIsConsultedAfterEnvVerdictOrder(): void
    {
        // Structural anti-regression: the environment verdict must be read
        // BEFORE TerminalProbe::run() inside terminalSupportsColor(), and the
        // probe must remain reachable (a verdict of null falls through).
        $src = implode('', array_slice(
            file((string) (new ReflectionMethod(RenderCommand::class, 'terminalSupportsColor'))->getFileName()),
            (new ReflectionMethod(RenderCommand::class, 'terminalSupportsColor'))->getStartLine() - 1,
            (new ReflectionMethod(RenderCommand::class, 'terminalSupportsColor'))->getEndLine()
                - (new ReflectionMethod(RenderCommand::class, 'terminalSupportsColor'))->getStartLine() + 1
        ));
        $verdict = strpos($src, 'colorDecisionFromEnvironment()');
        $probe   = strpos($src, 'TerminalProbe::run()');
        $this->assertNotFalse($verdict);
        $this->assertNotFalse($probe);
        $this->assertLessThan($probe, $verdict, 'env verdict must short-circuit before the probe');
        // And the whole `!== ''` inversion shape must never reappear.
        $this->assertStringNotContainsString("getenv('NO_COLOR') !== ''", $src);
    }

    public function testNoColorSpecHonorsZeroAndIgnoresEmpty(): void
    {
        // no-color.org: ANY non-empty value disables, including "0".
        putenv('NO_COLOR=0');
        $this->assertFalse($this->invokeStatic('colorDecisionFromEnvironment'));
        // Empty means "not set" for the verdict; the probe decides.
        putenv('NO_COLOR=');
        putenv('TERM=xterm-256color');
        $this->assertNull($this->invokeStatic('colorDecisionFromEnvironment'));
        $this->assertTrue($this->invokeStatic('terminalSupportsColor'));
    }

    public function testClicolorForceWinsOverNoColor(): void
    {
        // House ladder step: a force variable outranks NO_COLOR, and force
        // decorates even when the stream is not a terminal.
        putenv('NO_COLOR=1');
        putenv('CLICOLOR_FORCE=1');
        $this->assertTrue($this->invokeStatic('colorDecisionFromEnvironment'));

        RenderCommand::setTerminalCheckCallback(fn () => false);
        [$result, $payload] = $this->runPrint("# Hello", [
            ['theme-config', ''],
            ['style', null],
            ['theme', null],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString("\x1b[", $payload, 'CLICOLOR_FORCE decorates pipes');
    }

    public function testNoAnsiFlagStripsDecorationOnATty(): void
    {
        // --no-ansi is a decoration veto independent of tty-ness.
        RenderCommand::setTerminalCheckCallback(fn () => true);
        $input = $this->createMock(InputInterface::class);
        $input->method('hasOption')->willReturnMap([['no-ansi', true], ['ansi', true]]);
        $input->method('getOption')->willReturnMap([['no-ansi', true], ['ansi', false]]);
        $output = $this->createMock(OutputInterface::class);
        $output->method('isDecorated')->willReturn(true);
        $this->assertFalse($this->invokeStatic('decoratedOutput', [$input, $output]));
    }

    public function testAnsiFlagDecoratesAPipe(): void
    {
        RenderCommand::setTerminalCheckCallback(fn () => false);
        $input = $this->createMock(InputInterface::class);
        $input->method('hasOption')->willReturnMap([['no-ansi', true], ['ansi', true]]);
        $input->method('getOption')->willReturnMap([['no-ansi', false], ['ansi', true]]);
        $output = $this->createMock(OutputInterface::class);
        $this->assertTrue($this->invokeStatic('decoratedOutput', [$input, $output]));
    }

    public function testExplicitAnsiThemeIsAChoiceNotTheDefault(): void
    {
        // KM8: 'ansi' used to double as the option default AND the no-choice
        // sentinel, so `--theme ansi` fell into the downgrade branch and the
        // theme the flag names could never be selected on a colorless probe.
        RenderCommand::setTerminalCheckCallback(fn () => true);
        RenderCommand::setColorProbeCallback(fn () => false);
        [$result, $payload] = $this->runPrint("# Hello", [
            ['theme-config', ''],
            ['style', null],
            ['theme', 'ansi'], // EXPLICIT ansi
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString("\x1b[38;2;", $payload, 'explicit --theme ansi must paint even when the probe refuses color');
    }

    public function testAsciiThemeIsSelectableByName(): void
    {
        // KM10 reachable-acessibility face: the ascii arm exists in pickTheme.
        [$result, $payload] = $this->runPrint("# Hello\n\nWorld **bold**.", [
            ['theme-config', ''],
            ['style', null],
            ['theme', 'ascii'],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString("\x1b[1m", $payload);
        $this->assertDoesNotMatchRegularExpression('/\x1b\[(?:3[0-9]|4[0-9]|9[0-9]|38;|48;)[0-9;]*m/', $payload);
    }

    public function testPrintPathWritesRawUntouchedDocumentBytes(): void
    {
        // KC2: the print path previously handed the rendered document to the
        // Symfony formatter via writeln(), which ATE <info>…</info> tags,
        // restyled document text, and — the security face — INTERPRETED
        // hostile inline-HTML <href=…> tags into live OSC 8 links, bypassing
        // sugar-markdown's refused-scheme gate. OUTPUT_RAW makes the write a
        // byte conduit. Every claim here is red under the old writeln path
        // (formatter consumed/restyled the tags) and green under OUTPUT_RAW.
        RenderCommand::setTerminalCheckCallback(fn () => false);
        $doc = "Before <info>keepme</info> after\n\n"
            . "Closing </error> alone.\n\n"
            . "X <fg=red;options=bold>Y</fg> Z\n\n"
            . "<href=javascript:alert(1)>click</href> end";
        [$result, $payload, $newline, $type] = $this->runPrintCapturingArgs($doc, [
            ['theme-config', ''],
            ['style', null],
            ['theme', null],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertSame(OutputInterface::OUTPUT_RAW, $type, 'document bytes must bypass the formatter entirely');
        $this->assertStringContainsString('<info>keepme</info>', $payload);
        $this->assertStringContainsString('</error> alone.', $payload);
        $this->assertStringContainsString('<fg=red;options=bold>Y</fg>', $payload);
        $this->assertStringContainsString('<href=javascript:alert(1)>click</href>', $payload);
        // The formatter's interpretation (green SGR / live OSC-8) must be absent.
        $this->assertStringNotContainsString("\x1b[32m", $payload);
        $this->assertStringNotContainsString("\x1b]8;;javascript:", $payload);
        // Newline etiquette: append only when the render lacks a trailing one.
        $this->assertSame(str_ends_with($payload, "\n") === false, $newline);
    }

    public function testPrintPathAppendsNoNewlineAfterNewlineTerminatedRender(): void
    {
        // The other half of the str_ends_with arm: find content whose plain
        // render already ends in \n and prove no extra newline is requested.
        // A hard line-break list item ends the document with a newline even
        // after padding trim; if the render shape ever changes, the test
        // still pins the invariant (flag == !ends-with-newline) truthfully.
        RenderCommand::setTerminalCheckCallback(fn () => false);
        $doc = "- one\n- two\n";
        [$result, $payload, $newline, $type] = $this->runPrintCapturingArgs($doc, [
            ['theme-config', ''],
            ['style', null],
            ['theme', null],
            ['width', 0],
            ['pager', false],
            ['no-hyperlinks', false],
        ]);
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertSame(OutputInterface::OUTPUT_RAW, $type);
        $this->assertSame(str_ends_with($payload, "\n") === false, $newline);
    }

    public function testLoadInputEmptyStdinReturnsNull(): void
    {
        // Use a pipe that we control — php://temp is seekable and not a TTY.
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        // Stream is seekable but empty → should return null.
        $result = RenderCommand::loadInput('', $stream);
        fclose($stream);
        $this->assertNull($result);
    }

    public function testLoadInputReadsStdin(): void
    {
        // Use a pipe that we control — php://temp is seekable and not a TTY.
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, "# Hello from stdin");
        fseek($stream, 0); // Rewind to beginning so loadInput can read it.
        $result = RenderCommand::loadInput('', $stream);
        fclose($stream);
        $this->assertSame("# Hello from stdin", $result);
    }

    public function testExecuteUnreadableThemeConfigIsUsageError(): void
    {
        // KM5/KM21: this used to throw a raw InvalidArgumentException out of
        // execute() and print a multi-line Symfony exception block naming
        // RenderCommand.php and the line number. It is a refusal now: one
        // error line, INVALID exit code, no usage dump.
        $tmp = tempnam(sys_get_temp_dir(), 'mdview-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "# Hello");
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', '/no/such/theme.json'],
                ['style', null],
                ['theme', 'ansi'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $captured = '';
            $output   = $this->createMock(OutputInterface::class);
            $output->method('writeln')->willReturnCallback(
                static function ($messages) use (&$captured): void {
                    $captured .= (string) $messages;
                }
            );

            $command = new RenderCommand();
            $result  = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::INVALID, $result);
            $this->assertStringContainsString('theme config not readable', $captured);
            $this->assertStringContainsString('/no/such/theme.json', $captured);
        } finally {
            unlink($tmp);
        }
    }

    public function testExecuteRejectsOversizedThemeConfig(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdview-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "# Hello");
        $configTmp = tempnam(sys_get_temp_dir(), 'mdview-config-');
        $this->assertNotFalse($configTmp);
        // Valid JSON that WOULD decode fine, but exceeds the 1 MB byte cap.
        // Without the cap the decoder happily accepts it (the extra 'pad' key
        // is ignored); with the cap it is rejected before ever being decoded.
        // Reverting the cap makes this input load successfully -> no error
        // line and rc SUCCESS -> this test fails, which is exactly what pins
        // the guard in place.
        $huge = '{"paragraph":{"fg":"white","bg":"black"},"pad":"' . str_repeat('a', 1_000_001) . '"}';
        file_put_contents($configTmp, $huge);
        try {
            $input = $this->createMock(InputInterface::class);
            $input->method('getArgument')->with('file')->willReturn($tmp);
            $input->method('getOption')->willReturnMap([
                ['theme-config', $configTmp],
                ['style', null],
                ['theme', 'ansi'],
                ['width', 0],
                ['pager', false],
                ['no-hyperlinks', false],
            ]);

            $captured = '';
            $output   = $this->createMock(OutputInterface::class);
            $output->method('writeln')->willReturnCallback(
                static function ($messages) use (&$captured): void {
                    $captured .= (string) $messages;
                }
            );

            $command = new RenderCommand();
            $result  = $this->invokeExecute($command, $input, $output);
            $this->assertSame(Command::INVALID, $result);
            $this->assertStringContainsString('exceeds 1000000 bytes', $captured);
        } finally {
            unlink($tmp);
            unlink($configTmp);
        }
    }

    public function testBuildPagerModelWiresContentAndSize(): void
    {
        $rendered = "line1\nline2\nline3";
        $cols = 80;
        $rows = 5;

        $method = new \ReflectionMethod(RenderCommand::class, 'buildPagerModel');
        $method->setAccessible(true);

        $model = $method->invoke(null, $rendered, $cols, $rows);

        $this->assertInstanceOf(MdViewModel::class, $model);
        // View should contain the rendered content.
        $this->assertStringContainsString('line1', $model->view());
        // The model should not be exited initially.
        $this->assertFalse($model->isExited());
    }

    public function testBuildPagerModelDocumentModeAddsTheHeader(): void
    {
        $method = new \ReflectionMethod(RenderCommand::class, 'buildPagerModel');
        $method->setAccessible(true);

        $model = $method->invoke(null, "line1\nline2", 40, 6, 'doc.md');

        $header = \explode("\n", $model->view())[0];
        $this->assertStringContainsString('doc.md', $header);
        $this->assertStringContainsString('%', $header);
        $this->assertSame(5, $model->viewport->height, 'the header row is reserved inside the model');
    }

    public function testPagerArmsTheTtyKeyboardAndMouseWheel(): void
    {
        // KM3: piped stdin used to leave the pager a dead keyboard; openTty
        // re-opens /dev/tty. The wheel needs CellMotion — the Viewport has
        // handled MouseWheelMsg all along, Program simply never delivered it.
        $method = new \ReflectionMethod(RenderCommand::class, 'execute');
        $slice  = \implode("\n", \array_slice(
            \file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertMatchesRegularExpression('/openTty:\s*true/', $slice);
        $this->assertMatchesRegularExpression('/mouseMode:\s*MouseMode::CellMotion/', $slice);
    }

    public function testPagerFileWatchIsWiredIntoTheExecuteBranch(): void
    {
        // CLI half of the auto-reload wiring: execute() must hoist the file argument
        // and arm the pump ONLY for a file pager — stdin has nothing to
        // watch. Source-scan pin (house idiom): the wiring is a Program
        // branch that cannot be unit-invoked without blocking on run().
        $method = new ReflectionMethod(RenderCommand::class, 'execute');
        $lines  = array_slice(
            file((string) $method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        );
        $body = implode('', $lines);

        $this->assertMatchesRegularExpression(
            '/\$fileArg\s*=\s*\(string\)\s*\(\$input->getArgument\([\'"]file[\'"]\)/',
            $body,
            'execute() must hoist the file argument once for both branches'
        );
        $this->assertMatchesRegularExpression(
            '/if \(\$fileArg !== [\'"]{2}\) \{\s*\$model = self::armPagerWatch\(\$model, \$fileArg, \$renderer\);/',
            $body,
            'the pager branch must arm the watch behind the non-empty-file gate'
        );
        $this->assertSame(1, substr_count($body, 'self::armPagerWatch('), 'exactly one arming call site');
    }

    public function testArmPagerWatchReloadsThroughTheLiveRenderer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mdview-arm-') . '.md';
        file_put_contents($path, "# Alpha Title\n\nfirst body\n");

        try {
            $renderer = new Renderer(Theme::dark(), 60);
            $method   = new ReflectionMethod(RenderCommand::class, 'armPagerWatch');
            $method->setAccessible(true);
            $model = $method->invoke(null, MdViewModel::fromContent('stale', 60, 10), $path, $renderer);

            $this->assertTrue($model->isWatching());

            file_put_contents($path, "# Bravo Title\n\nsecond body\n");
            clearstatcache();

            [$reloaded, $cmd] = $model->update(new ReloadTickMsg());
            $this->assertNotNull($cmd, 'arming the watch keeps the re-arm chain alive');
            $this->assertSame(1, $reloaded->reloadCount);
            // Re-rendered through the live Renderer, not copied verbatim:
            // headings reach the view styled but text intact.
            $view = $reloaded->view();
            $this->assertStringContainsString('Bravo Title', $view);
            $this->assertStringNotContainsString('Alpha Title', $view);
        } finally {
            @unlink($path);
        }
    }

    // --- usage-error contract (KM5 / KM11 / KM12 / KM21) -------------------

    /**
     * Default option rows for execute() probes, with per-name overrides.
     *
     * @param array<string,mixed> $overrides
     *
     * @return list<array{0:string,1:mixed}> rows for willReturnMap
     */
    private function optionMap(array $overrides = []): array
    {
        $base = [
            'theme-config'  => '',
            'style'         => null,
            'theme'         => null,
            'width'         => 0,
            'pager'         => false,
            'no-hyperlinks' => false,
        ];
        $merged = array_merge($base, $overrides);
        return array_map(
            static fn(string $name, mixed $value): array => [$name, $value],
            array_keys($merged),
            $merged
        );
    }

    /**
     * Run execute() in print mode and capture every writeln() line the
     * command emits (writeError falls back to the given output for plain
     * OutputInterface mocks, so error lines are visible here).
     *
     * @param list<array{0:string,1:mixed}> $optionRows
     * @param array<string,mixed>           $argumentMap
     *
     * @return array{0:int,1:string} exit code + concatenated writeln payload
     */
    private function runUsageRun(?string $content, array $optionRows, array $argumentMap = []): array
    {
        $tmp = null;
        if ($content !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'mdview-');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $content);
        }
        try {
            $input = $this->createMock(InputInterface::class);
            $capturedFile = $tmp;
            $input->method('getArgument')->willReturnCallback(
                static fn(string $name): mixed => $argumentMap[$name] ?? ($name === 'file' ? $capturedFile : null)
            );
            $input->method('getOption')->willReturnMap($optionRows);

            $captured = '';
            $output   = $this->createMock(OutputInterface::class);
            $output->method('writeln')->willReturnCallback(
                static function ($messages) use (&$captured): void {
                    $captured .= (string) $messages;
                }
            );
            $output->method('write')->willReturnCallback(
                static function ($messages): void {
                    // Success payload — not captured; usage probes assert rc.
                }
            );

            return [$this->invokeExecute(new RenderCommand(), $input, $output), $captured];
        } finally {
            if ($tmp !== null) {
                unlink($tmp);
            }
        }
    }

    public function testMissingFileNamesThePath(): void
    {
        // glow names the path ("open <path>: no such file"); mdview must too.
        [$rc, $msg] = $this->runUsageRun(null, $this->optionMap(), ['file' => '/no/such/dir/candy-mdview-x.md']);
        $this->assertSame(Command::INVALID, $rc);
        $this->assertStringContainsString('candy-mdview-x.md', $msg);
        $this->assertStringContainsString('no such file', $msg);
    }

    public function testDirectoryArgumentFailsLoudly(): void
    {
        // KM4 keystone: `candy-mdview docs/` used to "succeed" — directory
        // reads returned string(0), passed the is_string gate, and rendered
        // an empty document with exit code 0. An exit-code lie is now a
        // named refusal.
        $dir = (string) sys_get_temp_dir();
        [$rc, $msg] = $this->runUsageRun(null, $this->optionMap(), ['file' => $dir]);
        $this->assertSame(Command::INVALID, $rc);
        $this->assertStringContainsString($dir, $msg);
        $this->assertStringContainsString('is a directory', $msg);
    }

    public function testBinaryFileIsRefused(): void
    {
        // KM11: a mistyped PNG/PDF/ELF path used to spray raw control bytes
        // at the terminal with SUCCESS. NUL bytes are conclusive refusal.
        [$rc, $msg] = $this->runUsageRun(str_repeat("\x7fELF\x00\x01\x02", 4), $this->optionMap());
        $this->assertSame(Command::INVALID, $rc);
        $this->assertStringContainsString('not a text file', $msg);
    }

    public function testUnicodeDocumentStillRenders(): void
    {
        // The refusal must not become a gate against real text: multi-byte
        // UTF-8 (and its emoji) carry no C0 bytes and sail through.
        [$result, $payload] = $this->runPrint("# 日本語 ünïcode 😀 ok\n", $this->optionMap());
        $this->assertSame(Command::SUCCESS, $result);
        $this->assertStringContainsString('日本語', $payload);
        $this->assertStringContainsString('😀', $payload);
    }

    public function testInvalidWidthValuesAreUsageErrors(): void
    {
        // KM12 rider: `--width abc` / negatives used to silently become 0
        // (no-wrap, then hard truncation). Fail fast, name the value.
        foreach (['-5', 'abc', '99999999', '1.5', '1 2'] as $bad) {
            [$rc, $msg] = $this->runUsageRun('# Hello', $this->optionMap(['width' => $bad]));
            $this->assertSame(Command::INVALID, $rc, "width {$bad} must be a usage error");
            $this->assertStringContainsString('--width', $msg);
            $this->assertStringContainsString($bad, $msg);
        }
    }

    public function testUnknownThemeIsUsageErrorNotException(): void
    {
        // KM5: theme faults used to leave execute() as raw exceptions and
        // print the Symfony usage dump. One line, INVALID, names the theme.
        [$rc, $msg] = $this->runUsageRun('# Hello', $this->optionMap(['theme' => 'ZORG']));
        $this->assertSame(Command::INVALID, $rc);
        $this->assertStringContainsString('unknown theme: ZORG', $msg);
    }

    public function testThemeFaultIsReportedBeforeMissingInput(): void
    {
        // KM5 ordering (copywriter-M3): the shipped execute() validated the
        // input first, so `--theme=ZORG missingfile.md` only ever said
        // "no input" — the bogus flag was never seen. Flags now resolve
        // before the document read.
        [$rc, $msg] = $this->runUsageRun(null, $this->optionMap(['theme' => 'ZORG']), ['file' => '/no/such/qq.md']);
        $this->assertSame(Command::INVALID, $rc);
        $this->assertStringContainsString('theme', $msg);
        $this->assertStringNotContainsString('no such file', $msg);
    }

    public function testEmptyStdinIsNamedDistinctly(): void
    {
        // Empty stdin and a tty stdin used to be one word. The injected
        // stream keeps this hermetic: no read of the runner's real STDIN.
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('standard input is empty');
            $this->invokeStatic('readDocument', ['', $stream]);
        } finally {
            fclose($stream);
        }
    }

    public function testBinaryStdinIsRefused(): void
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, "\x00\x01\x02binary");
        fseek($stream, 0);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('not a text file');
            $this->invokeStatic('readDocument', ['', $stream]);
        } finally {
            fclose($stream);
        }
    }

    public function testForgedEscapeBytesInArgvNeverReachTheErrorLine(): void
    {
        // KM21 (argv channel of the terminal-injection family): a crafted
        // `--theme=$'A\e[31mB'` used to land RAW in the error box, letting a
        // filename/theme emit live SGR at the user. Sanitize::cellValue
        // flattens every control byte to '·' (U+00B7) at the writeError choke.
        [$rc, $msg] = $this->runUsageRun('# Hello', $this->optionMap(['theme' => "A\x1b[31mB"]));
        $this->assertSame(Command::INVALID, $rc);
        $this->assertStringNotContainsString("\x1b", $msg, 'no escape byte may reach the error line');
        $this->assertStringContainsString('unknown theme: A' . "\u{00B7}" . '[31mB', $msg);
    }

    public function testForgedOscBytesInArgvNeverReachTheErrorLine(): void
    {
        // OSC-8 face of the same channel: the injection must not be able to
        // mint a clickable hyperlink from inside the error box either.
        [$rc, $msg] = $this->runUsageRun('# Hello', $this->optionMap(['theme' => "\x1b]8;;http://evil\x07x"]));
        $this->assertSame(Command::INVALID, $rc);
        $this->assertStringNotContainsString("\x1b", $msg);
        $this->assertStringNotContainsString("\x07", $msg);
    }

    public function testFormatterTagsInPathsCannotRestyleTheErrorLine(): void
    {
        // OutputFormatter::escape neutralises `<…>` from user paths: a
        // missing file literally named <info>... cannot open a green block.
        [$rc, $msg] = $this->runUsageRun(null, $this->optionMap(), ['file' => '/tmp/mdview-<info>zz']);
        $this->assertSame(Command::INVALID, $rc);
        $this->assertStringContainsString('\\<info\\>', $msg, 'tags arrive backslash-escaped to the formatter');
        $this->assertStringContainsString('zz', $msg);
    }

    public function testLoadInputGuardsStdinDefinitionBeforeUse(): void
    {
        // Structural pin (senior-m2, Law-1 ordering): the shipped arm read
        // `$stream ?? STDIN` BEFORE the defined('STDIN') check, making the
        // defensive branch dead code. The guard must precede the use.
        $ref  = new ReflectionMethod(RenderCommand::class, 'loadInput');
        $src  = implode('', array_slice(
            file((string) $ref->getFileName()),
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));
        $guard = strpos($src, "defined('STDIN')");
        $use   = strpos($src, '$stream = STDIN');
        $this->assertNotFalse($guard);
        $this->assertNotFalse($use);
        $this->assertLessThan($use, $guard, 'STDIN must be checked defined() before it is read');
    }
}
