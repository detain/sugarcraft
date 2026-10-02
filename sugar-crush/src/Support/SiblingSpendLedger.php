<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Usage;

/**
 * The append-only spend file one concurrent tool group shares, so that runs
 * forked side by side can see what each other has billed (audit B4-rem).
 *
 * WHY A FILE. {@see \SugarCraft\Crush\Runtime::executeConcurrently()} forks one
 * child per call, and a delegated Task run lives entirely inside its child: the
 * parent learns its spend only when the child's result payload is collected,
 * after the run is over. Two consequences followed, and this class closes both:
 *
 *  - SIBLINGS WERE BLIND TO EACH OTHER. Each run's spend-cap check started from
 *    the calling turn's spend at the instant of the fork and added only its own
 *    steps, so five parallel Tasks under a $1 cap could each spend up to the
 *    whole remaining dollar. Every member now {@see record()}s each step as it
 *    is billed, and every member's cap check adds {@see spentByOthers()}.
 *  - A CRASHED CHILD REPORTED NOTHING. A child that died before writing its
 *    payload (a fatal, OOM, SIGKILL) took every dollar its run had billed with
 *    it. The same records are on disk, so the parent recovers that member's
 *    spend with {@see spentBy()} on its crash arm.
 *
 * WHAT IS SHARED, AND WHAT IS NOT. One ledger per GROUP, created by the parent
 * before any child exists and discarded when the group is done. The children
 * of one group are born at the same instant, so none of them has a sibling's
 * spend in its starting baseline: summing the others' records can never count
 * a dollar twice. The parent never reads the ledger for a member that DID
 * return a result — that result's own usage is the authority, and the records
 * are only the fallback for one that did not.
 *
 * The file is created 0600 and exclusively (`x`, so a planted name is refused,
 * never followed), under {@see ToolIpcFiles::RUNTIME_PREFIX} so that a
 * process killed mid-group leaves it to the same one-hour sweep as the group's
 * payloads. A member never CREATES it: {@see record()} opens it `r+`, so a
 * child still running after the parent discarded the ledger writes nothing
 * rather than resurrect a file nobody will collect.
 *
 * Lines are JSON (`{"m": member, "u": Usage::toArray()}`), appended under an
 * exclusive lock and read under a shared one, each written between newlines.
 * A line that does not decode — a write a SIGKILL cut short — is skipped: it
 * costs that one step its accounting, never the read, and never the next
 * record, which starts on a line of its own.
 */
final readonly class SiblingSpendLedger
{
    private function __construct(
        private string $path,
        private string $member,
    ) {}

    /**
     * Create an empty ledger for one group — the parent's half. Null when the
     * file cannot be made; the group then runs exactly as it did before this
     * class existed (each member sees only its own spend), which is a weaker
     * cap, not a broken turn.
     */
    public static function create(): ?self
    {
        $path = ToolIpcFiles::reserve(ToolIpcFiles::RUNTIME_PREFIX, 'spend');

        $previous = umask(0o077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($previous);
        }

        if ($handle === false) {
            return null;
        }
        fclose($handle);

        return new self($path, '');
    }

    /** The same ledger, as seen by the group member $member. */
    public function forMember(string $member): self
    {
        return new self($this->path, $member);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function member(): string
    {
        return $this->member;
    }

    /**
     * Append one billed step under this member's name. Null is "nothing
     * reported" and writes nothing. Never throws: a ledger that cannot be
     * written costs the siblings a view of this step, not the step itself.
     */
    public function record(?Usage $usage): void
    {
        if ($usage === null) {
            return;
        }

        $line = json_encode(['m' => $this->member, 'u' => $usage->toArray()]);
        if ($line === false) {
            return;
        }

        // r+, never a/c: see the class docblock on why a member never creates.
        $handle = @fopen($this->path, 'r+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            fseek($handle, 0, SEEK_END);
            // Led by a newline too, so a line a SIGKILL tore before its own
            // newline is closed off here instead of swallowing this one.
            fwrite($handle, "\n" . $line . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** What every OTHER member of the group has recorded so far, or null for nothing. */
    public function spentByOthers(): ?Usage
    {
        $usages = [];
        foreach ($this->entries() as [$member, $usage]) {
            if ($member !== $this->member) {
                $usages[] = $usage;
            }
        }

        return Usage::sum($usages);
    }

    /** What $member has recorded so far, or null for nothing — the crash arm's figure. */
    public function spentBy(string $member): ?Usage
    {
        $usages = [];
        foreach ($this->entries() as [$owner, $usage]) {
            if ($owner === $member) {
                $usages[] = $usage;
            }
        }

        return Usage::sum($usages);
    }

    /** Remove the file — the parent's half, once the group is done. */
    public function discard(): void
    {
        ToolIpcFiles::discard($this->path);
    }

    /**
     * @return list<array{0: string, 1: Usage}>
     */
    private function entries(): array
    {
        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }
            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $entries = [];
        foreach (explode("\n", (string) $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded) || !is_string($decoded['m'] ?? null)) {
                continue;
            }
            $usage = Usage::fromArray($decoded['u'] ?? null);
            if ($usage !== null) {
                $entries[] = [$decoded['m'], $usage];
            }
        }

        return $entries;
    }
}
