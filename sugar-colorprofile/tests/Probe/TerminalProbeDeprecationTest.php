<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests\Probe;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\ColorProfile\AsyncProbe;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\ColorProfile\Probe\TerminalProbe;
use SugarCraft\ColorProfile\Probe\TerminfoReplayProbe;

/**
 * Pins the deprecation window on the synchronous TerminalProbe twin (plan
 * P1-15). Structural, like StandardColorsDeprecationTest: the tag is read from
 * the docblock, and the replacement surface is read through reflection. No
 * runtime notice is raised, because consumers in sibling libraries still call
 * the class and must not start failing under failOnDeprecation.
 *
 * @covers \SugarCraft\ColorProfile\Probe\TerminalProbe
 */
final class TerminalProbeDeprecationTest extends TestCase
{
    public function testTheSyncTwinCarriesTheRemovalTagAndTheReplacementPointer(): void
    {
        $doc = (string) (new ReflectionClass(TerminalProbe::class))->getDocComment();

        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString('REMOVED-IN: 2.0.0', $doc);
        $this->assertStringContainsString('AsyncProbe::report()', $doc, 'the docblock must name the replacement');
    }

    public function testTheAsyncReplacementExposesReportOnTheLoopContract(): void
    {
        $method = new ReflectionMethod(AsyncProbe::class, 'report');

        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertTrue($method->getParameters()[0]->allowsNull());
        $this->assertTrue($method->getParameters()[0]->isOptional());
        $this->assertSame(LoopInterface::class, $method->getParameters()[0]->getType()?->getName());
        $this->assertSame(PromiseInterface::class, $method->getReturnType()?->getName());
    }

    public function testTheReplayDriverIsAnInternalFinalSeam(): void
    {
        $class = new ReflectionClass(TerminfoReplayProbe::class);

        $this->assertTrue($class->isFinal());
        $this->assertStringContainsString('@internal', (string) $class->getDocComment());
        $this->assertTrue($class->isSubclassOf(TerminalProbe::class));
    }

    public function testTheSyncTwinStillAnswersForBackCompatibility(): void
    {
        $this->assertInstanceOf(ProbeReport::class, TerminalProbe::run());
    }
}
