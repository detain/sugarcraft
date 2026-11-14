<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\MouseMode;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\ProgramOptions\ProgramOptionsBuilder;
use SugarCraft\Core\Util\ColorProfile;
use PHPUnit\Framework\TestCase;

final class ProgramOptionsBuilderTest extends TestCase
{
    public function testBuildWithDefaults(): void
    {
        $builder = new ProgramOptionsBuilder();
        $options = $builder->build();

        $this->assertInstanceOf(ProgramOptions::class, $options);
        $this->assertFalse($options->useAltScreen);
        $this->assertTrue($options->catchInterrupts);
        $this->assertTrue($options->hideCursor);
        $this->assertSame(60.0, $options->framerate);
        $this->assertSame(MouseMode::Off, $options->mouseMode);
        $this->assertFalse($options->reportFocus);
        $this->assertFalse($options->bracketedPaste);
        $this->assertTrue($options->unicodeMode);
        $this->assertFalse($options->inlineMode);
        $this->assertFalse($options->openTty);
        $this->assertNull($options->input);
        $this->assertNull($options->output);
        $this->assertNull($options->loop);
        $this->assertNull($options->environment);
        $this->assertNull($options->windowSize);
        $this->assertNull($options->colorProfile);
        $this->assertTrue($options->catchPanics);
        $this->assertFalse($options->withoutRenderer);
        $this->assertNull($options->filter);
        $this->assertFalse($options->cellDiffRenderer);
        $this->assertFalse($options->withoutSignalHandler);
        $this->assertNull($options->termios);
        $this->assertNull($options->subscriptions);
    }

    public function testBuildWithCustomValues(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withUseAltScreen(true)
            ->withCatchInterrupts(false)
            ->withHideCursor(false)
            ->withFramerate(30.0)
            ->withMouseMode(MouseMode::CellMotion)
            ->withReportFocus(true)
            ->withBracketedPaste(true)
            ->withUnicodeMode(false)
            ->withInlineMode(true)
            ->withOpenTty(true)
            ->withCatchPanics(false)
            ->withWithoutRenderer(true)
            ->withCellDiffRenderer(true)
            ->withWithoutSignalHandler(true)
            ->build();

        $this->assertTrue($options->useAltScreen);
        $this->assertFalse($options->catchInterrupts);
        $this->assertFalse($options->hideCursor);
        $this->assertSame(30.0, $options->framerate);
        $this->assertSame(MouseMode::CellMotion, $options->mouseMode);
        $this->assertTrue($options->reportFocus);
        $this->assertTrue($options->bracketedPaste);
        $this->assertFalse($options->unicodeMode);
        $this->assertTrue($options->inlineMode);
        $this->assertTrue($options->openTty);
        $this->assertFalse($options->catchPanics);
        $this->assertTrue($options->withoutRenderer);
        $this->assertTrue($options->cellDiffRenderer);
        $this->assertTrue($options->withoutSignalHandler);
    }

    public function testSynchronizedOutputDefaultsOnAndBuilderTurnsItOff(): void
    {
        $this->assertTrue((new ProgramOptions())->synchronizedOutput);
        $this->assertTrue(ProgramOptions::builder()->build()->synchronizedOutput);
        $this->assertFalse(ProgramOptions::builder()->withSynchronizedOutput(false)->build()->synchronizedOutput);
    }

    public function testBuilderWithInvalidFramerate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Framerate must be positive');

        (new ProgramOptionsBuilder())
            ->withFramerate(0.0)
            ->build();
    }

    public function testBuilderWithInvalidWindowSizeMissingKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('windowSize must have cols and rows keys');

        (new ProgramOptionsBuilder())
            ->withWindowSize(['cols' => 80])
            ->build();
    }

    public function testBuilderWithInvalidWindowSizeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('windowSize cols and rows must be positive');

        (new ProgramOptionsBuilder())
            ->withWindowSize(['cols' => -1, 'rows' => 24])
            ->build();
    }

    public function testBuilderWithValidWindowSize(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withWindowSize(['cols' => 120, 'rows' => 40])
            ->build();

        $this->assertSame(['cols' => 120, 'rows' => 40], $options->windowSize);
    }

    /**
     * sc-19 (finding sugar-core-021): the old test pinned the OPPOSITE
     * contract (`with*()` returned the same mutable instance). Every
     * `with*()` now returns a fresh clone, so a builder forked
     * mid-chain must not cross-contaminate its siblings or its parent.
     */
    public function testBuilderForksDoNotShareState(): void
    {
        $base = (new ProgramOptionsBuilder())
            ->withFramerate(25.0)
            ->withUseAltScreen(true);

        $branchA = $base->withHideCursor(false);
        $branchB = $base->withHideCursor(true)->withMouseMode(MouseMode::CellMotion);

        $this->assertNotSame($base, $branchA);
        $this->assertNotSame($branchA, $branchB);

        // Parent unaffected by either branch...
        $this->assertTrue($base->build()->hideCursor);
        $this->assertFalse($base->build()->withoutRenderer);
        // ...branches keep the shared trunk, carry only their own fork.
        $this->assertFalse($branchA->build()->hideCursor);
        $this->assertTrue($branchA->build()->useAltScreen);
        $this->assertSame(25.0, $branchA->build()->framerate);
        $this->assertSame(MouseMode::Off, $branchA->build()->mouseMode);
        $this->assertSame(MouseMode::CellMotion, $branchB->build()->mouseMode);
        // The fork pair itself: A hides nothing... B keeps the hide —
        // same trunk, opposite arms, neither leaks into the other.
        $this->assertTrue($branchB->build()->hideCursor);
    }

    public function testWithReturnsFreshInstanceIdentity(): void
    {
        $builder = new ProgramOptionsBuilder();
        $result  = $builder->withFramerate(25.0);

        $this->assertNotSame($builder, $result);
        // No-op chain position: the original builder still builds defaults.
        $this->assertSame(60.0, $builder->build()->framerate);
        $this->assertSame(25.0, $result->build()->framerate);
    }

    public function testValidationIsEagerAtTheSetterNotDeferredToBuild(): void
    {
        // The class docblock used to promise build()-time validation;
        // reality is (and stays) eager — the throw happens on the
        // with*() call, before build() is ever reached.
        $builder = new ProgramOptionsBuilder();
        try {
            $builder->withFramerate(-1.0);
            $this->fail('withFramerate deferred its throw past the setter');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Framerate must be positive', $e->getMessage());
        }
        // The rejected setter left the parent untouched and unmutated.
        $this->assertSame(60.0, $builder->build()->framerate);
    }

    public function testNullIsTheMeaningForNullableKnobsAndResetsAreExpressible(): void
    {
        // No $XSet sentinels by decision (see class docblock): setting
        // null is identical to never having set it, so a reset is
        // expressible as `->withLoop(null)` with no extra ceremony.
        $with = (new ProgramOptionsBuilder())->withEnvironment(['FOO' => 'bar'])->build();
        $this->assertSame(['FOO' => 'bar'], $with->environment);

        $reset = (new ProgramOptionsBuilder())
            ->withEnvironment(['FOO' => 'bar'])
            ->withEnvironment(null)
            ->build();
        $never = (new ProgramOptionsBuilder())->build();
        $this->assertNull($reset->environment);
        $this->assertEquals($never, $reset);

        // Same truth for a mixed stream knob: null restores the default.
        $cleared = (new ProgramOptionsBuilder())->withOutput(STDERR)->withOutput(null)->build();
        $this->assertNull($cleared->output);
    }

    public function testProgramOptionsHasStaticBuilderMethod(): void
    {
        $options = ProgramOptions::builder()->build();

        $this->assertInstanceOf(ProgramOptions::class, $options);
    }

    public function testWithInput(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withInput(STDOUT)
            ->build();

        $this->assertSame(STDOUT, $options->input);
    }

    public function testWithOutput(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withOutput(STDERR)
            ->build();

        $this->assertSame(STDERR, $options->output);
    }

    public function testWithLoop(): void
    {
        $loop = $this->createMock(\React\EventLoop\LoopInterface::class);

        $options = (new ProgramOptionsBuilder())
            ->withLoop($loop)
            ->build();

        $this->assertSame($loop, $options->loop);
    }

    public function testWithLoopNullable(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withLoop(null)
            ->build();

        $this->assertNull($options->loop);
    }

    public function testWithEnvironment(): void
    {
        $env = ['FOO' => 'bar', 'BAZ' => 'qux'];

        $options = (new ProgramOptionsBuilder())
            ->withEnvironment($env)
            ->build();

        $this->assertSame($env, $options->environment);
    }

    public function testWithEnvironmentNullable(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withEnvironment(null)
            ->build();

        $this->assertNull($options->environment);
    }

    public function testWithColorProfile(): void
    {
        $colorProfile = \SugarCraft\Core\Util\ColorProfile::TrueColor;

        $options = (new ProgramOptionsBuilder())
            ->withColorProfile($colorProfile)
            ->build();

        $this->assertSame($colorProfile, $options->colorProfile);
    }

    public function testWithColorProfileNullable(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withColorProfile(null)
            ->build();

        $this->assertNull($options->colorProfile);
    }

    public function testWithFilter(): void
    {
        $filter = fn () => null;

        $options = (new ProgramOptionsBuilder())
            ->withFilter($filter)
            ->build();

        $this->assertSame($filter, $options->filter);
    }

    public function testWithFilterNullable(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withFilter(null)
            ->build();

        $this->assertNull($options->filter);
    }

    public function testWithTermios(): void
    {
        $termios = $this->createMock(\SugarCraft\Pty\Contract\Termios::class);

        $options = (new ProgramOptionsBuilder())
            ->withTermios($termios)
            ->build();

        $this->assertSame($termios, $options->termios);
    }

    public function testWithTermiosNullable(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withTermios(null)
            ->build();

        $this->assertNull($options->termios);
    }

    public function testWithSubscriptions(): void
    {
        $subscriptions = fn () => null;

        $options = (new ProgramOptionsBuilder())
            ->withSubscriptions($subscriptions)
            ->build();

        $this->assertSame($subscriptions, $options->subscriptions);
    }

    public function testWithSubscriptionsNullable(): void
    {
        $options = (new ProgramOptionsBuilder())
            ->withSubscriptions(null)
            ->build();

        $this->assertNull($options->subscriptions);
    }

    /**
     * sc-35 (sugar-core-037/DX-22): the honest without* names own the
     * knobs, the bool arm can switch them back off, and the deprecated
     * double-negative aliases keep producing byte-identical options.
     */
    public function testWithoutKnobsAndTheirDeprecatedAliasesAgree(): void
    {
        $primary = ProgramOptions::builder()
            ->withoutRenderer()
            ->withoutSignalHandler()
            ->build();
        $this->assertTrue($primary->withoutRenderer);
        $this->assertTrue($primary->withoutSignalHandler);

        $reset = ProgramOptions::builder()
            ->withoutRenderer(true)
            ->withoutRenderer(false)
            ->withoutSignalHandler(true)
            ->withoutSignalHandler(false)
            ->build();
        $this->assertFalse($reset->withoutRenderer);
        $this->assertFalse($reset->withoutSignalHandler);

        $alias = (new ProgramOptionsBuilder())
            ->withWithoutRenderer(true)
            ->withWithoutSignalHandler(true)
            ->build();
        $this->assertTrue($alias->withoutRenderer);
        $this->assertTrue($alias->withoutSignalHandler);
    }
}
