<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Share;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Share\ShareRecord;
use SugarCraft\Code\Share\ShareStore;

final class ShareRecordTest extends TestCase
{
    public function testItCarriesItsFieldsAndIsStaticByDefault(): void
    {
        $r = new ShareRecord('tok', 'ses-1', 'html', '<p>x</p>', 100, 200);

        self::assertSame('tok', $r->token);
        self::assertSame('ses-1', $r->sessionId);
        self::assertSame('html', $r->format);
        self::assertSame('<p>x</p>', $r->html);
        self::assertSame(100, $r->createdAt);
        self::assertSame(200, $r->expiresAt);
        self::assertFalse($r->live);
    }

    public function testLiveCanBeSet(): void
    {
        self::assertTrue((new ShareRecord('t', 's', 'html', '', 1, 2, true))->live);
    }

    public function testPathIsTheUrlPrefixThenTheToken(): void
    {
        $r = new ShareRecord('abc123', 's', 'html', '', 1, 2);

        self::assertSame('/' . ShareStore::URL_PREFIX . '/abc123', $r->path());
        self::assertSame('/s/abc123', $r->path());
    }
}
