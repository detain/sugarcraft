<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Contract;

use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\Process;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Contract\Pump;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Pty\Contract\Termios;
use PHPUnit\Framework\TestCase;

final class InterfacesTest extends TestCase
{
    /**
     * @dataProvider interfaceProvider
     * @phpstan-template T
     * @phpstan-param class-string<T> $fqcn
     */
    public function testInterfaceAutoloads(string $fqcn): void
    {
        $this->assertTrue(
            \interface_exists($fqcn),
            "Interface {$fqcn} must be autoloadable",
        );
    }

    /**
     * @dataProvider interfaceProvider
     * @param class-string $fqcn
     */
    public function testNoMethodsAreStatic(string $fqcn): void
    {
        $reflection = new \ReflectionClass($fqcn);
        foreach ($reflection->getMethods() as $method) {
            $this->assertFalse(
                $method->isStatic(),
                "Method {$fqcn}::{$method->getName()} must not be static",
            );
        }
    }

    /**
     * @dataProvider interfaceProvider
     * @param class-string $fqcn
     */
    public function testAllMethodsHaveReturnType(string $fqcn): void
    {
        $reflection = new \ReflectionClass($fqcn);
        foreach ($reflection->getMethods() as $method) {
            $this->assertTrue(
                $method->hasReturnType(),
                "Method {$fqcn}::{$method->getName()} must have a return type",
            );
        }
    }

    /**
     * @dataProvider interfaceProvider
     * @param class-string $fqcn
     */
    public function testAllMethodsArePublic(string $fqcn): void
    {
        $reflection = new \ReflectionClass($fqcn);
        foreach ($reflection->getMethods() as $method) {
            $this->assertTrue(
                $method->isPublic(),
                "Method {$fqcn}::{$method->getName()} must be public",
            );
        }
    }

    /**
     * @dataProvider interfaceProvider
     * @param class-string $fqcn
     */
    public function testDocCommentCitesUpstream(string $fqcn): void
    {
        $reflection = new \ReflectionClass($fqcn);
        $doc = $reflection->getDocComment();
        $this->assertNotFalse($doc, "{$fqcn} must have a doc-comment");
        $this->assertMatchesRegularExpression(
            '/@(see|mirrors)\\s+(creack|portable-pty|charmbracelet)/i',
            $doc,
            "{$fqcn} doc-comment must cite an upstream library",
        );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function interfaceProvider(): iterable
    {
        yield 'PtySystem' => [PtySystem::class];
        yield 'PtyPair' => [PtyPair::class];
        yield 'MasterPty' => [MasterPty::class];
        yield 'SlavePty' => [SlavePty::class];
        yield 'Child' => [Child::class];
        yield 'Process' => [Process::class];
        yield 'Termios' => [Termios::class];
        yield 'Pump' => [Pump::class];
    }

    public function testPtySystemHasExpectedMethods(): void
    {
        $methods = self::methodNames(PtySystem::class);
        $this->assertContains('open', $methods);
        $this->assertContains('capabilities', $methods);
    }

    public function testPtyPairHasExpectedMethods(): void
    {
        $methods = self::methodNames(PtyPair::class);
        $this->assertContains('master', $methods);
        $this->assertContains('slave', $methods);
    }

    public function testMasterPtyHasExpectedMethods(): void
    {
        $methods = self::methodNames(MasterPty::class);
        $this->assertContains('read', $methods);
        $this->assertContains('write', $methods);
        $this->assertContains('resize', $methods);
        $this->assertContains('size', $methods);
        $this->assertContains('stream', $methods);
        $this->assertContains('close', $methods);
    }

    public function testMasterPtyHasSignalConstants(): void
    {
        $this->assertSame(15, MasterPty::SIGTERM);
        $this->assertSame(9, MasterPty::SIGKILL);
        $this->assertSame(2, MasterPty::SIGINT);
    }

    public function testSlavePtyHasExpectedMethods(): void
    {
        $methods = self::methodNames(SlavePty::class);
        $this->assertContains('path', $methods);
        $this->assertContains('spawn', $methods);
    }

    public function testChildHasExpectedMethods(): void
    {
        $methods = self::methodNames(Child::class);
        $this->assertContains('pid', $methods);
        $this->assertContains('exited', $methods);
        $this->assertContains('wait', $methods);
        $this->assertContains('exitCode', $methods);
        $this->assertContains('kill', $methods);
    }

    public function testChildHasSignalConstants(): void
    {
        $this->assertSame(15, Child::SIGTERM);
        $this->assertSame(9, Child::SIGKILL);
        $this->assertSame(2, Child::SIGINT);
    }

    public function testProcessHasExpectedMethods(): void
    {
        $methods = self::methodNames(Process::class);
        $this->assertContains('pid', $methods);
        $this->assertContains('exited', $methods);
        $this->assertContains('wait', $methods);
        $this->assertContains('exitCode', $methods);
        $this->assertContains('kill', $methods);
        $this->assertContains('stdoutBytes', $methods);
        $this->assertContains('stderrBytes', $methods);
    }

    public function testProcessHasSignalConstants(): void
    {
        $this->assertSame(15, Process::SIGTERM);
        $this->assertSame(9, Process::SIGKILL);
        $this->assertSame(2, Process::SIGINT);
    }

    public function testTermiosHasExpectedMethods(): void
    {
        $methods = self::methodNames(Termios::class);
        $this->assertContains('current', $methods);
        $this->assertContains('makeRaw', $methods);
        $this->assertContains('apply', $methods);
        $this->assertContains('restore', $methods);
        $this->assertContains('isAtty', $methods);
    }

    public function testTermiosHasTcsanowConstant(): void
    {
        $this->assertSame(0, Termios::TCSANOW);
    }

    public function testPumpHasExpectedMethods(): void
    {
        $methods = self::methodNames(Pump::class);
        $this->assertContains('run', $methods);
    }

    /**
     * @param class-string $fqcn
     * @return list<string>
     */
    private static function methodNames(string $fqcn): array
    {
        return \array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass($fqcn))->getMethods(),
        );
    }
}
