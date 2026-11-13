<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Lang;

/** S-O39: -p value consumption and refusal-copy polish. */
final class ArgvValueConsumptionTest extends TestCase
{
    public function testPromptEqualsFormIsConsumedAsTheValueOfP(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', '--prompt=-hello']);
        $this->assertNull($args->usageError);
        $this->assertSame('-hello', $args->prompt);
    }

    public function testPFollowedByVersionIsRefusedAndDoesNotPrintVersion(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', '--version']);
        $this->assertNotNull($args->usageError);
        $this->assertFalse($args->version);
    }

    public function testConfigDirectoryIsNamedAsDirectory(): void
    {
        $args = ArgvParser::parse(['candy-code', '--config', \sys_get_temp_dir()]);
        $this->assertStringContainsString('is a directory', (string) ArgvParser::configError($args));
    }

    public function testRootThatIsAFileIsNotReportedAsMissing(): void
    {
        $args = ArgvParser::parse(['candy-code', '--root', __FILE__]);
        $this->assertStringContainsString('not a directory', (string) ArgvParser::rootError($args));
    }

    public function testStdinCapLabelIsSharedByHelpAndRuntimeNotice(): void
    {
        $this->assertStringContainsString(
            '{stdin-cap} stdin cap',
            (string) \file_get_contents(__DIR__ . '/../../lang/en.php'),
            'the help page quotes the constant, not a literal',
        );
        $this->assertStringContainsString(
            NonInteractive::STDIN_CAP_LABEL . ' stdin cap',
            \SugarCraft\Code\Cli\Help::screen(),
        );
        $this->assertStringContainsString(
            NonInteractive::STDIN_CAP_LABEL,
            Lang::t('cli.noninteractive.stdin_truncated', ['cap' => NonInteractive::STDIN_CAP_LABEL]),
        );
    }

    public function testStdinCapLabelMatchesTheByteConstant(): void
    {
        $bytes = (new \ReflectionClassConstant(NonInteractive::class, 'MAX_STDIN_BYTES'))->getValue();
        $this->assertSame(($bytes / 1048576) . ' MiB', NonInteractive::STDIN_CAP_LABEL);
    }

    public function testPositionalDirectoryErrorEchoesTheOperandNotAFlag(): void
    {
        $args = ArgvParser::parse(['candy-code', '/no/such/dir']);
        $error = (string) ArgvParser::rootError($args);
        $this->assertStringNotContainsString('--root', $error);
        $this->assertStringContainsString('/no/such/dir: no such directory', $error);

        $file = ArgvParser::parse(['candy-code', __FILE__]);
        $this->assertStringContainsString(__FILE__ . ': not a directory', (string) ArgvParser::rootError($file));

        $flag = ArgvParser::parse(['candy-code', '--root', '/no/such/dir']);
        $this->assertStringContainsString('--root /no/such/dir: no such directory', (string) ArgvParser::rootError($flag));
    }

    public function testOptionsMaySitBetweenPAndItsText(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', '--output-format', 'json', 'hello world']);
        $this->assertNull($args->usageError);
        $this->assertSame('hello world', $args->prompt);
        $this->assertSame('json', $args->outputFormat);
        $this->assertSame([], $args->positionals);

        $long = ArgvParser::parse(['candy-code', '--prompt', '--model', 'm', 'hi']);
        $this->assertNull($long->usageError);
        $this->assertSame('hi', $long->prompt);
        $this->assertSame('m', $long->model);
    }

    public function testPWithOnlyOptionsAndNoTextStillRefuses(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', '--output-format', 'json']);
        $this->assertStringContainsString('-p expects a prompt', (string) $args->usageError);
        $this->assertStringContainsString('--output-format', (string) $args->usageError);
    }

    public function testPFollowedByEndOfOptionsNamesTheMarker(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', '--', 'hi']);
        $this->assertStringContainsString('end-of-options marker', (string) $args->usageError);
        $this->assertStringContainsString('--prompt=<text>', (string) $args->usageHint);
    }

    public function testHeadlessNoticeNamesBothSpellings(): void
    {
        $this->assertStringContainsString('-p/--prompt', Lang::t('appLaunch.notice.headless_default'));
    }
}
