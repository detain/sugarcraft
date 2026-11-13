<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support\Directories;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\Directories\DirectoryEntry;
use SugarCraft\Code\Support\Directories\DirectoryListing;

final class DirectoryListingTest extends TestCase
{
    public function testToArrayProjectsEveryFieldAndNestsEntries(): void
    {
        $listing = new DirectoryListing(
            '/srv/app',
            '/srv',
            [new DirectoryEntry('a', '/srv/app/a', true, true), new DirectoryEntry('b', '/srv/app/b', false, false)],
            true,
            true,
            false,
        );

        self::assertSame([
            'path' => '/srv/app',
            'parent' => '/srv',
            'entries' => [
                ['name' => 'a', 'path' => '/srv/app/a', 'project' => true, 'readable' => true],
                ['name' => 'b', 'path' => '/srv/app/b', 'project' => false, 'readable' => false],
            ],
            'truncated' => true,
            'readable' => true,
            'project' => false,
        ], $listing->toArray());
    }

    public function testTheRootHasANullParentAndNoEntries(): void
    {
        $arr = (new DirectoryListing('/', null, [], false, false, false))->toArray();

        self::assertNull($arr['parent']);
        self::assertSame([], $arr['entries']);
        self::assertFalse($arr['readable']);
    }
}
