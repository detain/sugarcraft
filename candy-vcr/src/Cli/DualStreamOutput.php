<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The Application::run() door hands verbs a plain StreamOutput wrapping the
 * caller's stdout — but the machine-readable paths (render-tape --dry-run's
 * JSONL stream) write clean data to stdout and MUST route diagnostics to
 * stderr, and they can only do that through ConsoleOutputInterface. Without
 * a dual implementation the S09 warnings channel quietly died inside the
 * library-embedded CLI (finding 024): the `instanceof ConsoleOutputInterface`
 * gate in RenderTapeCommand simply never fired through Application::run().
 *
 * This output pair is that door: stdout gets the stream the caller passed,
 * `getErrorOutput()` gets the stderr stream, and the command's diagnostic
 * writes land on the second one.
 */
final class DualStreamOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $errorOutput;

    /** @var array<array-key, ConsoleSectionOutput> */
    private array $sections = [];

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct($stdout, $stderr, int $verbosity = self::VERBOSITY_NORMAL)
    {
        parent::__construct($stdout, $verbosity);
        $this->errorOutput = new StreamOutput($stderr, $verbosity);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        $sections = &$this->sections;
        return new ConsoleSectionOutput(
            $this->getStream(),
            $sections,
            $this->getVerbosity(),
            $this->isDecorated(),
            $this->getFormatter(),
        );
    }
}
