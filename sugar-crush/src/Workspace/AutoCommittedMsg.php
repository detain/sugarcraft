<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workspace;

use SugarCraft\Core\Msg;
use SugarCraft\Crush\Usage;

/**
 * Internal Msg carrying one `turn`-mode auto-commit's outcome (step 3.G) back
 * to {@see \SugarCraft\Crush\Chat}: the commit was already made — in this
 * process, as the commit-message request settled — so what is left is the
 * bookkeeping: account $usage (the title model's call is on the user's key),
 * and one transcript notice saying what was committed, or why not.
 */
final class AutoCommittedMsg implements Msg
{
    /**
     * @param list<string> $paths the files the commit took
     * @param ?string $sha the commit, null when it was not made
     * @param ?string $snapshot the commit that saved the user's own earlier changes first, if one was needed
     * @param ?string $error why nothing was committed, null on success
     */
    public function __construct(
        public readonly ?string $sha = null,
        public readonly string $subject = '',
        public readonly array $paths = [],
        public readonly ?string $snapshot = null,
        public readonly ?string $error = null,
        public readonly ?Usage $usage = null,
        public readonly ?string $sessionId = null,
    ) {}

    /** The transcript line for this outcome. */
    public function notice(): string
    {
        if ($this->sha === null) {
            return 'Auto-commit skipped: ' . ($this->error ?? 'nothing was committed') . '.';
        }
        $files = \count($this->paths) === 1 ? '1 file' : \count($this->paths) . ' files';
        $first = $this->snapshot !== null
            ? ' (your earlier changes to them went first, in ' . substr($this->snapshot, 0, 7) . ')'
            : '';

        return 'Auto-committed ' . substr($this->sha, 0, 7) . ' ' . $this->subject . " — {$files}{$first}. /undo reverts it.";
    }
}
