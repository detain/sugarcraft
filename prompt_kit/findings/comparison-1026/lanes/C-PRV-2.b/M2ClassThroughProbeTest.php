<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

trait ProbeConstViaClass
{
    public const MARKER = 'class-through-ok';
}

final class ProbeUsesIt
{
    use ProbeConstViaClass;
}

final class M2ClassThroughProbeTest extends TestCase
{
    public function testClassConstantViaUsingClass(): void
    {
        $this->assertSame('class-through-ok', \ProbeUsesIt::MARKER);
    }
}
