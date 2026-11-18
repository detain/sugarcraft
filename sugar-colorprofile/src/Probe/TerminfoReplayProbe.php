<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Probe;

/**
 * @internal Two-pass driver behind {@see \SugarCraft\ColorProfile\AsyncProbe::report()}.
 *
 * The terminfo leg of {@see TerminalProbe} is the only step that needs a
 * child process, and it reaches that child through one seam: runCommand().
 * A recording pass stands in for that seam, answers nothing, and notes the
 * TERM it was asked about. The leg reaches the seam only after every gate
 * (suppression, TERM shape, binary) has passed, so the request itself says a
 * child is needed. A replaying pass answers the seam with the child's dump, so
 * the composed report is the sync report by construction.
 */
final class TerminfoReplayProbe extends TerminalProbe
{
    /** TERM the terminfo leg asked about, or null when the leg never asked. */
    private ?string $requested = null;

    /**
     * @param array<string, string|null> $environment Complete replacement environment ([] reads the ambient one)
     */
    private function __construct(
        array $environment,
        private readonly bool $replaying,
        private readonly ?string $dump,
    ) {
        parent::__construct($environment);
    }

    /**
     * A pass that answers the terminfo leg with nothing and records the request.
     *
     * @param array<string, string|null> $environment Complete replacement environment ([] reads the ambient one)
     */
    public static function recording(array $environment = []): self
    {
        return new self($environment, false, null);
    }

    /**
     * A pass that answers the terminfo leg with the given child dump (null when
     * the child produced none: timed out, killed, or failed to launch).
     *
     * @param array<string, string|null> $environment Complete replacement environment ([] reads the ambient one)
     */
    public static function replaying(?string $dump, array $environment = []): self
    {
        return new self($environment, true, $dump);
    }

    /** The TERM the terminfo leg asked about, or null when it never asked. */
    public function requestedTerm(): ?string
    {
        return $this->requested;
    }

    protected function runCommand(string $cmd): ?string
    {
        $this->requested = (string) $this->getEnv('TERM');

        return $this->replaying ? $this->dump : null;
    }
}
