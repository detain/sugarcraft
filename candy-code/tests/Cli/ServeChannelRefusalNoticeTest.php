<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Serve;

/**
 * The `bundles.channels.refused` launch notice under `candy-code serve`. A
 * project-tier refusal of a trusted name is announced once, by Bootstrap's
 * `bundles.project_tier_trust_refused` notice; the channel refusal list must
 * not repeat it with a reason set that has no project-tier entry.
 */
final class ServeChannelRefusalNoticeTest extends TestCase
{
    public function testAProjectTierRefusalIsLeftOutOfTheChannelRefusalNotice(): void
    {
        $rows = [
            ['bundle' => 'shadow', 'path' => '/p/entry.php', 'class' => 'Impl', 'kind' => 'channel', 'reason' => 'project-tier'],
        ];

        self::assertSame([], Serve::channelRefusalsForNotice($rows));
    }

    public function testFileLevelRefusalsStayInTheChannelRefusalNotice(): void
    {
        $ownership = ['bundle' => 'side', 'path' => '/u/entry.php', 'class' => 'Impl', 'kind' => 'channel', 'reason' => 'foreign-owner'];
        $project = ['bundle' => 'shadow', 'path' => '/p/entry.php', 'class' => 'Impl', 'kind' => 'channel', 'reason' => 'project-tier'];
        $collision = ['bundle' => 'side', 'name' => 'Impl', 'class' => 'Impl', 'kind' => 'channel', 'reason' => 'collision'];

        $kept = Serve::channelRefusalsForNotice([$ownership, $project, $collision]);

        self::assertSame([$ownership, $collision], $kept, 'only the project-tier row is dropped, and order is preserved');
        self::assertSame([0, 1], array_keys($kept), 'the list is re-indexed for the notice');
    }

    public function testAnEmptyRefusalListStaysEmpty(): void
    {
        self::assertSame([], Serve::channelRefusalsForNotice([]));
    }
}
