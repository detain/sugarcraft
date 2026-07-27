<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    public function testTResolvesKnownKey(): void
    {
        $result = Lang::t('theme.read_failed');
        $this->assertSame('could not read theme file: {path}', $result);
    }

    public function testTWithPlaceholder(): void
    {
        $result = Lang::t('theme.bad_color', ['spec' => '#xyz']);
        $this->assertSame('invalid colour spec: #xyz', $result);
    }

    public function testTReturnsNamespacedKeyWhenNotFound(): void
    {
        $result = Lang::t('nonexistent.key');
        $this->assertSame('shine.nonexistent.key', $result);
    }

    public function testTIsIdempotent(): void
    {
        $a = Lang::t('theme.json_invalid');
        $b = Lang::t('theme.json_invalid');
        $this->assertSame($a, $b);
    }
}
