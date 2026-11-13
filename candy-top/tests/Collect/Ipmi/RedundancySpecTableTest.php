<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Ipmi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Ipmi\IpmiParser;
use SugarCraft\Top\Collect\Ipmi\IpmiReadings;
use SugarCraft\Top\Collect\Ipmi\Redundancy;
use SugarCraft\Top\Collect\Ipmi\SensorStatus;

/**
 * Pins Redundancy against the IPMI v2.0 generic reading type 0x0B offset
 * table, so a misreading of the spec cannot recur silently.
 */
final class RedundancySpecTableTest extends TestCase
{
    /** @return iterable<string, array{int, Redundancy}> */
    public static function singleOffsets(): iterable
    {
        yield 'offset 0 Fully Redundant' => [0x01, Redundancy::Full];
        yield 'offset 1 Redundancy Lost' => [0x02, Redundancy::Lost];
        yield 'offset 2 Redundancy Degraded' => [0x04, Redundancy::Degraded];
        yield 'offset 3 Non-redundant: Sufficient from Redundant' => [0x08, Redundancy::NonRedundantSufficient];
        yield 'offset 4 Non-redundant: Sufficient from Insufficient' => [0x10, Redundancy::NonRedundantSufficient];
        yield 'offset 5 Non-redundant: Insufficient Resources' => [0x20, Redundancy::NonRedundantInsufficient];
        yield 'offset 6 Redundancy Degraded from Fully Redundant' => [0x40, Redundancy::Degraded];
        yield 'offset 7 Redundancy Degraded from Non-redundant' => [0x80, Redundancy::Degraded];
    }

    #[DataProvider('singleOffsets')]
    public function testEachSpecOffset(int $mask, Redundancy $expected): void
    {
        $this->assertSame($expected, Redundancy::fromState($mask));
    }

    /** @return iterable<string, array{int, Redundancy}> */
    public static function combined(): iterable
    {
        yield 'full+lost is lost' => [0x03, Redundancy::Lost];
        yield 'full+insufficient is insufficient' => [0x21, Redundancy::NonRedundantInsufficient];
        yield 'lost+degraded: critical beats non-critical' => [0x06, Redundancy::Lost];
        yield 'full+sufficient is sufficient' => [0x09, Redundancy::NonRedundantSufficient];
        yield 'degraded+sufficient tie goes to lowest offset' => [0x0C, Redundancy::Degraded];
        yield 'lost+insufficient tie goes to lowest offset' => [0x22, Redundancy::Lost];
        yield 'bits above the spec ceiling are ignored' => [0x101, Redundancy::Full];
    }

    #[DataProvider('combined')]
    public function testWorstSeverityWins(int $mask, Redundancy $expected): void
    {
        $this->assertSame($expected, Redundancy::fromState($mask));
    }

    public function testNothingAssertedIsNull(): void
    {
        $this->assertNull(Redundancy::fromState(0));
        $this->assertNull(Redundancy::fromState(null));
        $this->assertNull(Redundancy::fromState(0x100), 'only a bit past the spec ceiling');
    }

    /** @return iterable<string, array{string, Redundancy}> */
    public static function texts(): iterable
    {
        yield 'Fully Redundant' => ['Fully Redundant', Redundancy::Full];
        yield 'Redundancy Lost' => ['Redundancy Lost', Redundancy::Lost];
        yield 'Redundancy Degraded' => ['Redundancy Degraded', Redundancy::Degraded];
        yield 'Non-Redundant: Sufficient from Redundant' => ['Non-Redundant: Sufficient from Redundant', Redundancy::NonRedundantSufficient];
        yield 'Non-Redundant: Sufficient from Insufficient' => ['Non-Redundant: Sufficient from Insufficient', Redundancy::NonRedundantSufficient];
        yield 'Non-Redundant: Insufficient Resources' => ['Non-Redundant: Insufficient Resources', Redundancy::NonRedundantInsufficient];
        yield 'Redundancy Degraded from Fully Redundant' => ['Redundancy Degraded from Fully Redundant', Redundancy::Degraded];
        yield 'Redundancy Degraded from Non-Redundant' => ['Redundancy Degraded from Non-Redundant', Redundancy::Degraded];
    }

    #[DataProvider('texts')]
    public function testTextParityWithOffsets(string $text, Redundancy $expected): void
    {
        $this->assertSame($expected, Redundancy::fromText($text));
    }

    public function testSyntheticSensorListUsesBitmaskFallback(): void
    {
        $r = IpmiReadings::of(IpmiParser::sensorList($this->fixture('sensor-list.synthetic.txt')));
        $this->assertSame(Redundancy::Lost, $r->redundancy);
        $this->assertSame(SensorStatus::Critical, $r->worst());
    }

    public function testSyntheticSdrElistUsesText(): void
    {
        $r = IpmiReadings::of(IpmiParser::sdrElist($this->fixture('sdr-elist.synthetic.txt')));
        $this->assertSame(Redundancy::Lost, $r->redundancy);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/fixtures/ipmi/spec-0b/' . $name);
    }
}
