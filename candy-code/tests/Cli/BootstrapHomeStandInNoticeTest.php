<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Tests\Support\SlicesDeclaredMethodsTrait;

/**
 * v1:m-1, the NOTICE half: a launch standing on the temp-directory home
 * stand-in says so once, through the same drain as every other launch
 * fact. The stand-in itself is unchanged behaviour — the ADEXED reader
 * inventory on {@see HomeDirectory::path()} lists WHO reads it, and the
 * loud refusal paths for owned-home consumers ({@code cli.refuse.no_home})
 * stay exactly as they were.
 */
final class BootstrapHomeStandInNoticeTest extends TestCase
{
    use SlicesDeclaredMethodsTrait;

    protected function setUp(): void
    {
        HomeDirectory::resetStandInUsedForTesting();
        $this->resetNoticeStatics();
    }

    protected function tearDown(): void
    {
        HomeDirectory::resetStandInUsedForTesting();
        $this->resetNoticeStatics();
    }

    public function testAProcessThatEstablishedItsHomeDrainsNothing(): void
    {
        Bootstrap::reportHomeDirectoryStandIn();

        $this->assertSame([], Bootstrap::launchNotices(), 'no stand-in, no row — a normal launch stays byte-unchanged');
    }

    public function testTheStandInRecordsOneRowNamingTheRemedy(): void
    {
        $this->forceStandInRecorded();

        Bootstrap::reportHomeDirectoryStandIn();

        $notices = Bootstrap::launchNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('HOME could not be established', $notices[0]);
        $this->assertStringContainsString('export HOME', $notices[0], 'the row says how to make it stop');
        $this->assertStringContainsString('temp-directory stand-in', $notices[0]);
    }

    public function testTheRepeatDrainAddsNothingBecauseTheRowIsStable(): void
    {
        $this->forceStandInRecorded();

        Bootstrap::reportHomeDirectoryStandIn();
        Bootstrap::reportHomeDirectoryStandIn();

        $this->assertCount(1, Bootstrap::launchNotices(), 'the seam de-dupes by message identity, however many reads took the stand-in');
    }

    public function testTheDrainRunsFromChatAndNothingElseNeedsToKnow(): void
    {
        $bootstrapFile = \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php';
        $lines = file($bootstrapFile);
        $method = new \ReflectionMethod(Bootstrap::class, 'chat');
        // E325 direction one (plan_comparison W4 weld): reflection's line
        // numbers address the file the method is DECLARED in while the scrape
        // reads one named path. True by construction today; asserted so a
        // future extraction of chat() into a trait cannot silently scrape
        // unrelated lines — the defect the reader census exists to catch.
        $this->assertSame(
            $bootstrapFile,
            $method->getFileName(),
            'chat() is no longer declared in ' . $bootstrapFile . '; read the body from'
                . ' $method->getFileName() or keep the drain where it is.',
        );
        $slice = self::declaredSlice($lines, 'chat', $method->getStartLine(), $method->getEndLine());

        $this->assertSame(
            1,
            substr_count($slice, 'self::reportHomeDirectoryStandIn();'),
            'chat() must drain the stand-in row exactly once — every other launch mode builds through chat()',
        );
    }

    private function forceStandInRecorded(): void
    {
        $property = new \ReflectionProperty(HomeDirectory::class, 'standInUsed');
        $property->setAccessible(true);
        $property->setValue(null, true);
    }

    private function resetNoticeStatics(): void
    {
        foreach ([
            'launchNotices' => [],
            'launchNoticesDropped' => [],
            'reportedPermissionConfigWarnings' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(LaunchNotices::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
