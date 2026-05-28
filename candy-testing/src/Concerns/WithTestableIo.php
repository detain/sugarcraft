<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Concerns;

/**
 * Trait for Programs that want testable I/O streams.
 *
 * Programs implementing this trait expose withInput() and withOutput()
 * hooks so {@see \SugarCraft\Testing\ProgramSimulator} can inject
 * scripted input and capture output without touching real file descriptors.
 *
 * Usage in a Program subclass:
 *   use WithTestableIo;
 *
 *   public function withInput(resource $input): static { return $this->mutate(['input' => $input]); }
 *   public function withOutput(resource $output): static { return $this->mutate(['output' => $output]); }
 *
 * Note: The actual Program::withInput/withOutput API addition is step-09.
 * This trait defines the consumer-side contract that Programs can opt into.
 */
trait WithTestableIo
{
    /**
     * Return a new instance with the input stream replaced.
     *
     * @param resource $input
     * @return static
     */
    abstract public function withInput($input): static;

    /**
     * Return a new instance with the output stream replaced.
     *
     * @param resource $output
     * @return static
     */
    abstract public function withOutput($output): static;
}
