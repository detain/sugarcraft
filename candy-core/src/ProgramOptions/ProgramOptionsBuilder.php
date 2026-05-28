<?php

declare(strict_types=1);

namespace SugarCraft\Core\ProgramOptions;

use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Pty\Contract\Termios;
use React\EventLoop\LoopInterface;

/**
 * Fluent builder for {@see ProgramOptions}.
 *
 * Example:
 *   $opts = (new ProgramOptionsBuilder())
 *       ->withUseAltScreen(true)
 *       ->withFramerate(30.0)
 *       ->build();
 *
 * All options have sane defaults matching {@see ProgramOptions} defaults.
 * The builder validates constraints when {@see build()} is called.
 */
final class ProgramOptionsBuilder
{
    private bool $useAltScreen = false;
    private bool $catchInterrupts = true;
    private bool $hideCursor = true;
    private float $framerate = 60.0;
    private \SugarCraft\Core\MouseMode $mouseMode = \SugarCraft\Core\MouseMode::Off;
    private bool $reportFocus = false;
    private bool $bracketedPaste = false;
    private bool $unicodeMode = true;
    private bool $inlineMode = false;
    private bool $openTty = false;
    private mixed $input = null;
    private mixed $output = null;
    private ?LoopInterface $loop = null;
    private ?array $environment = null;
    private ?array $windowSize = null;
    private ?ColorProfile $colorProfile = null;
    private bool $catchPanics = true;
    private bool $withoutRenderer = false;
    private ?\Closure $filter = null;
    private bool $cellDiffRenderer = false;
    private bool $withoutSignalHandler = false;
    private ?Termios $termios = null;
    private ?\Closure $subscriptions = null;

    public function withUseAltScreen(bool $useAltScreen): self
    {
        $this->useAltScreen = $useAltScreen;
        return $this;
    }

    public function withCatchInterrupts(bool $catchInterrupts): self
    {
        $this->catchInterrupts = $catchInterrupts;
        return $this;
    }

    public function withHideCursor(bool $hideCursor): self
    {
        $this->hideCursor = $hideCursor;
        return $this;
    }

    public function withFramerate(float $framerate): self
    {
        if ($framerate <= 0) {
            throw new \InvalidArgumentException('Framerate must be positive');
        }
        $this->framerate = $framerate;
        return $this;
    }

    public function withMouseMode(\SugarCraft\Core\MouseMode $mouseMode): self
    {
        $this->mouseMode = $mouseMode;
        return $this;
    }

    public function withReportFocus(bool $reportFocus): self
    {
        $this->reportFocus = $reportFocus;
        return $this;
    }

    public function withBracketedPaste(bool $bracketedPaste): self
    {
        $this->bracketedPaste = $bracketedPaste;
        return $this;
    }

    public function withUnicodeMode(bool $unicodeMode): self
    {
        $this->unicodeMode = $unicodeMode;
        return $this;
    }

    public function withInlineMode(bool $inlineMode): self
    {
        $this->inlineMode = $inlineMode;
        return $this;
    }

    public function withOpenTty(bool $openTty): self
    {
        $this->openTty = $openTty;
        return $this;
    }

    public function withInput(mixed $input): self
    {
        $this->input = $input;
        return $this;
    }

    public function withOutput(mixed $output): self
    {
        $this->output = $output;
        return $this;
    }

    public function withLoop(?LoopInterface $loop): self
    {
        $this->loop = $loop;
        return $this;
    }

    public function withEnvironment(?array $environment): self
    {
        $this->environment = $environment;
        return $this;
    }

    public function withWindowSize(?array $windowSize): self
    {
        if ($windowSize !== null) {
            if (!isset($windowSize['cols'], $windowSize['rows'])) {
                throw new \InvalidArgumentException('windowSize must have cols and rows keys');
            }
            if ($windowSize['cols'] <= 0 || $windowSize['rows'] <= 0) {
                throw new \InvalidArgumentException('windowSize cols and rows must be positive');
            }
        }
        $this->windowSize = $windowSize;
        return $this;
    }

    public function withColorProfile(?ColorProfile $colorProfile): self
    {
        $this->colorProfile = $colorProfile;
        return $this;
    }

    public function withCatchPanics(bool $catchPanics): self
    {
        $this->catchPanics = $catchPanics;
        return $this;
    }

    public function withWithoutRenderer(bool $withoutRenderer): self
    {
        $this->withoutRenderer = $withoutRenderer;
        return $this;
    }

    public function withFilter(?\Closure $filter): self
    {
        $this->filter = $filter;
        return $this;
    }

    public function withCellDiffRenderer(bool $cellDiffRenderer): self
    {
        $this->cellDiffRenderer = $cellDiffRenderer;
        return $this;
    }

    public function withWithoutSignalHandler(bool $withoutSignalHandler): self
    {
        $this->withoutSignalHandler = $withoutSignalHandler;
        return $this;
    }

    public function withTermios(?Termios $termios): self
    {
        $this->termios = $termios;
        return $this;
    }

    public function withSubscriptions(?\Closure $subscriptions): self
    {
        $this->subscriptions = $subscriptions;
        return $this;
    }

    /**
     * Build the {@see ProgramOptions} instance.
     */
    public function build(): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: $this->useAltScreen,
            catchInterrupts: $this->catchInterrupts,
            hideCursor: $this->hideCursor,
            framerate: $this->framerate,
            mouseMode: $this->mouseMode,
            reportFocus: $this->reportFocus,
            bracketedPaste: $this->bracketedPaste,
            unicodeMode: $this->unicodeMode,
            inlineMode: $this->inlineMode,
            openTty: $this->openTty,
            input: $this->input,
            output: $this->output,
            loop: $this->loop,
            environment: $this->environment,
            windowSize: $this->windowSize,
            colorProfile: $this->colorProfile,
            catchPanics: $this->catchPanics,
            withoutRenderer: $this->withoutRenderer,
            filter: $this->filter,
            cellDiffRenderer: $this->cellDiffRenderer,
            withoutSignalHandler: $this->withoutSignalHandler,
            termios: $this->termios,
            subscriptions: $this->subscriptions,
        );
    }
}
