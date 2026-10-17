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

    public function testBuilderReturnsSameInstanceOnEachWithCall(): void
    {
        $builder = new ProgramOptionsBuilder();
        $result = $builder->withFramerate(25.0);

        $this->assertSame($builder, $result);
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
}
