<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\Sandbox\SandboxFactory;
use SugarCraft\Code\Tools\Sandbox\Seatbelt;

/**
 * C-TOOL-4.b: the factory is the ONLY place the OS answers which jail a
 * `bashSandbox` value asks for — one table, the shared vocabulary, and the
 * unknown-is-strictest law kept per backend.
 *
 * These pins inject $os because the routing decision is what is being
 * tested; the live row can only ever show the host's own answer, and this
 * suite runs on Linux.
 */
final class SandboxFactoryTest extends TestCase
{
    public function testDarwinGetsTheSeatbeltBackend(): void
    {
        $sandbox = SandboxFactory::fromSetting('on', 'Darwin');

        self::assertInstanceOf(Seatbelt::class, $sandbox);
        self::assertSame('seatbelt', $sandbox->name());
        self::assertSame('on', $sandbox->mode());
    }

    public function testLinuxKeepsTheBubblewrapBackend(): void
    {
        $sandbox = SandboxFactory::fromSetting('on', 'Linux');

        self::assertInstanceOf(Bubblewrap::class, $sandbox);
        self::assertSame('bubblewrap', $sandbox->name());
    }

    public function testWindowsIsLeftOnBubblewrapOnPurpose(): void
    {
        // The honest Windows answer is "the configured jail cannot start",
        // which is Bubblewrap's standing fail-closed refusal — a third
        // behaviour invented for Windows would be a second, weaker law.
        $sandbox = SandboxFactory::fromSetting('on', 'Windows');

        self::assertInstanceOf(Bubblewrap::class, $sandbox);
    }

    public function testTheOffVocabularyReachesNullOnEveryOs(): void
    {
        foreach (['Darwin', 'Linux', 'Windows'] as $os) {
            self::assertNull(SandboxFactory::fromSetting(null, $os), $os);
            self::assertNull(SandboxFactory::fromSetting(false, $os), $os);
            self::assertNull(SandboxFactory::fromSetting('off', $os), $os);
        }
    }

    public function testAnUnrecognisedValueIsTheStrictestModeNeverOffOnEitherBackend(): void
    {
        foreach (['no-netwrok', 'ON', 1, ['on']] as $typo) {
            $label = json_encode($typo);
            $darwin = SandboxFactory::fromSetting($typo, 'Darwin');
            $linux = SandboxFactory::fromSetting($typo, 'Linux');

            self::assertSame('no-network', $darwin?->mode(), $label . ' must not read as off on ' . Seatbelt::class);
            self::assertSame('no-network', $linux?->mode(), $label . ' must not read as off on ' . Bubblewrap::class);
        }
    }

    public function testTheRealHostIsAnsweredByDefault(): void
    {
        $sandbox = SandboxFactory::fromSetting('on');

        self::assertInstanceOf(
            \PHP_OS_FAMILY === 'Darwin' ? Seatbelt::class : Bubblewrap::class,
            $sandbox,
        );
        self::assertInstanceOf(SandboxBackend::class, $sandbox);
    }

    public function testTheSeatbeltProbeRefusesThisHostBeforeTouchingTheBinary(): void
    {
        // macOS-only by construction, and the OS answer comes FIRST: on a
        // Linux host the probe refuses from the one fact that is true, with
        // the run closure never reached and the verdict memo never written.
        // The synthetic binary isolates the memo on macOS: a verdict stored
        // under the real binary's key would let this fake answer poison the
        // live jail leg's probe.
        $called = false;
        $refusal = Seatbelt::new('/seatbelt-factory-probe-absent-sentinel')->probe(
            static function (string $command) use (&$called): array {
                $called = true;

                return ['exitCode' => 0, 'stderr' => ''];
            },
        );

        if (\PHP_OS_FAMILY === 'Darwin') {
            // On its own OS the probe does reach the exec path (the closure
            // answers for it) — the arms the REAL binary takes live in
            // SeatbeltTest, which asserts both against /usr/bin/sandbox-exec.
            self::assertTrue($called);
            self::assertNull($refusal);

            return;
        }

        self::assertFalse($called, 'the OS gate must fire before any exec path');
        self::assertSame('seatbelt (sandbox-exec) is macOS-only and this host is ' . \PHP_OS_FAMILY, $refusal);
    }
}
