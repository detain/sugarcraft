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
 *
 * The builder is IMMUTABLE (sc-19, finding sugar-core-021): every
 * `with*()` returns a fresh instance via a private clone-mutate, per the
 * repo-wide fluent law (`sugar-style/src/Style.php` canonical). The
 * previous shape mutated `$this` and returned it, so a shared builder
 * cross-contaminated every chain forked from it — and the docblock
 * claimed validation ran at {@see build()} when both guards
 * (`withFramerate`, `withWindowSize`) actually fire EAGERLY at the
 * setter. `build()` itself is unconditional assembly.
 *
 * Nullable knobs carry no `$XSet` sentinel: for every one of them
 * (input/output/loop/environment/windowSize/colorProfile/filter/termios/
 * subscriptions) null IS the meaning — "leave the ProgramOptions
 * default" — so set-null and unset are observably identical to the
 * caller, which is precisely the house-law condition for NOT pairing a
 * sentinel. A reset is therefore expressible as `->withLoop(null)` and
 * behaves the same as never calling it.
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
    private bool $sanitizePaste = true;
    private bool $exitOnStdinEof = true;
    private bool $synchronizedOutput = true;

    /**
     * The single mutate door: clone, set one field, return the new
     * instance. `clone` is a shallow copy, which is exactly right here —
     * every field is either a scalar or a reference-carrying handle the
     * caller owns outside the builder (streams, loop, closures).
     *
     * @param string $field one of this class's private knobs
     */
    private function mutate(string $field, mixed $value): self
    {
        $clone        = clone $this;
        $clone->{$field} = $value;
        return $clone;
    }

    public function withUseAltScreen(bool $useAltScreen): self
    {
        return $this->mutate('useAltScreen', $useAltScreen);
    }

    public function withCatchInterrupts(bool $catchInterrupts): self
    {
        return $this->mutate('catchInterrupts', $catchInterrupts);
    }

    public function withHideCursor(bool $hideCursor): self
    {
        return $this->mutate('hideCursor', $hideCursor);
    }

    public function withFramerate(float $framerate): self
    {
        if ($framerate <= 0) {
            throw new \InvalidArgumentException('Framerate must be positive');
        }
        return $this->mutate('framerate', $framerate);
    }

    public function withMouseMode(\SugarCraft\Core\MouseMode $mouseMode): self
    {
        return $this->mutate('mouseMode', $mouseMode);
    }

    public function withReportFocus(bool $reportFocus): self
    {
        return $this->mutate('reportFocus', $reportFocus);
    }

    public function withBracketedPaste(bool $bracketedPaste): self
    {
        return $this->mutate('bracketedPaste', $bracketedPaste);
    }

    public function withUnicodeMode(bool $unicodeMode): self
    {
        return $this->mutate('unicodeMode', $unicodeMode);
    }

    public function withInlineMode(bool $inlineMode): self
    {
        return $this->mutate('inlineMode', $inlineMode);
    }

    public function withOpenTty(bool $openTty): self
    {
        return $this->mutate('openTty', $openTty);
    }

    public function withInput(mixed $input): self
    {
        return $this->mutate('input', $input);
    }

    public function withOutput(mixed $output): self
    {
        return $this->mutate('output', $output);
    }

    public function withLoop(?LoopInterface $loop): self
    {
        return $this->mutate('loop', $loop);
    }

    public function withEnvironment(?array $environment): self
    {
        return $this->mutate('environment', $environment);
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
        return $this->mutate('windowSize', $windowSize);
    }

    public function withColorProfile(?ColorProfile $colorProfile): self
    {
        return $this->mutate('colorProfile', $colorProfile);
    }

    public function withCatchPanics(bool $catchPanics): self
    {
        return $this->mutate('catchPanics', $catchPanics);
    }

    public function withWithoutRenderer(bool $withoutRenderer): self
    {
        return $this->mutate('withoutRenderer', $withoutRenderer);
    }

    public function withFilter(?\Closure $filter): self
    {
        return $this->mutate('filter', $filter);
    }

    public function withCellDiffRenderer(bool $cellDiffRenderer): self
    {
        return $this->mutate('cellDiffRenderer', $cellDiffRenderer);
    }

    public function withWithoutSignalHandler(bool $withoutSignalHandler): self
    {
        return $this->mutate('withoutSignalHandler', $withoutSignalHandler);
    }

    public function withTermios(?Termios $termios): self
    {
        return $this->mutate('termios', $termios);
    }

    public function withSubscriptions(?\Closure $subscriptions): self
    {
        return $this->mutate('subscriptions', $subscriptions);
    }

    public function withSanitizePaste(bool $sanitizePaste): self
    {
        return $this->mutate('sanitizePaste', $sanitizePaste);
    }

    public function withExitOnStdinEof(bool $exitOnStdinEof): self
    {
        return $this->mutate('exitOnStdinEof', $exitOnStdinEof);
    }

    public function withSynchronizedOutput(bool $synchronizedOutput): self
    {
        return $this->mutate('synchronizedOutput', $synchronizedOutput);
    }

    /**
     * Assemble the {@see ProgramOptions} from the current knob set.
     * Unconditional: both value constraints are enforced eagerly at
     * their setters (see class docblock), so build() never throws for a
     * well-typed chain.
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
            sanitizePaste: $this->sanitizePaste,
            exitOnStdinEof: $this->exitOnStdinEof,
            synchronizedOutput: $this->synchronizedOutput,
        );
    }
}
