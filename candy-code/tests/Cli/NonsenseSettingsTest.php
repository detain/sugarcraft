<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\NonsenseSettings;

final class NonsenseSettingsTest extends TestCase
{
    public function testIsSetTreatsMissingNullAndEmptyStringAsUnset(): void
    {
        self::assertFalse(NonsenseSettings::isSet([], 'k'));
        self::assertFalse(NonsenseSettings::isSet(['k' => null], 'k'));
        self::assertFalse(NonsenseSettings::isSet(['k' => ''], 'k'));
    }

    public function testIsSetAcceptsEveryOtherValueIncludingFalsyOnes(): void
    {
        foreach ([0, '0', false, [], 'x', 1.5] as $v) {
            self::assertTrue(NonsenseSettings::isSet(['k' => $v], 'k'), var_export($v, true));
        }
    }

    public function testValueForNoticeEncodesScalarsAsJson(): void
    {
        self::assertSame('"abc"', NonsenseSettings::valueForNotice('abc'));
        self::assertSame('12', NonsenseSettings::valueForNotice(12));
        self::assertSame('1.0', NonsenseSettings::valueForNotice(1.0), 'zero fraction preserved');
        self::assertSame('true', NonsenseSettings::valueForNotice(true));
        self::assertSame('null', NonsenseSettings::valueForNotice(null));
    }

    public function testValueForNoticeKeepsSlashesAndUnicodeReadable(): void
    {
        self::assertSame('"a/b"', NonsenseSettings::valueForNotice('a/b'));
        self::assertSame('"héllo ✓"', NonsenseSettings::valueForNotice('héllo ✓'));
    }

    public function testValueForNoticeClipsAtSixtyCharactersWithAnEllipsis(): void
    {
        $exactly60 = str_repeat('a', 58);
        self::assertSame(60, mb_strlen(NonsenseSettings::valueForNotice($exactly60)));
        self::assertStringEndsNotWith('…', NonsenseSettings::valueForNotice($exactly60));

        $long = NonsenseSettings::valueForNotice(str_repeat('é', 100));
        self::assertSame(60, mb_strlen($long, 'UTF-8'));
        self::assertStringEndsWith('…', $long);
    }

    public function testValueForNoticeNeverThrowsOnAnUnencodableValue(): void
    {
        $fh = fopen('php://memory', 'r');
        try {
            self::assertSame('null', NonsenseSettings::valueForNotice($fh), 'partial output substitutes null for a resource');
        } finally {
            fclose($fh);
        }

        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }
        $out = NonsenseSettings::valueForNotice($deep);
        self::assertStringStartsWith('[[[', $out, 'a too-deep value still yields a clipped partial rendering');
        self::assertSame(60, mb_strlen($out));
    }
}
