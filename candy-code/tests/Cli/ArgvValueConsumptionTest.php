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
            NonInteractive::STDIN_CAP_LABEL . ' stdin cap',
            (string) \file_get_contents(__DIR__ . '/../../lang/en.php'),
        );
        $this->assertStringContainsString(
            NonInteractive::STDIN_CAP_LABEL,
            Lang::t('cli.noninteractive.stdin_truncated', ['cap' => NonInteractive::STDIN_CAP_LABEL]),
        );
    }

    public function testHeadlessNoticeNamesBothSpellings(): void
    {
        $this->assertStringContainsString('-p/--prompt', Lang::t('appLaunch.notice.headless_default'));
    }
}
