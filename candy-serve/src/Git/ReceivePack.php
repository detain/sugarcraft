<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Git;

use SugarCraft\Serve\{AccessControl, Repo, User};

/**
 * Handles git-receive-pack requests (push).
 *
 * Port of charmbracelet/soft-serve Git.ReceivePack.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class ReceivePack
{
    private Repo $repo;
    private ?User $user;

    public function __construct(Repo $repo, ?User $user = null)
    {
        $this->repo = $repo;
        $this->user = $user;
    }

    public function repo(): Repo
    {
        return $this->repo;
    }

    /**
     * Serve a git-receive-pack session over stdio.
     */
    public function serve(): int
    {
        $ac = AccessControl::getInstance();

        if (!$ac->canWrite($this->user, $this->repo)) {
            $viewer = $this->user?->username ?? 'anonymous';
            \fwrite(\STDERR, "Access denied: {$viewer} cannot push to {$this->repo->name}\n");
            return 1;
        }

        // Auto-init repo if it doesn't exist (for on-demand creation)
        if (!$this->repo->exists()) {
            $this->repo->init();
        }

        $refs = $this->repo->refs();
        $this->advertiseRefs($refs);

        // Read commands and process push
        $commands = $this->readCommands();
        if ($commands === []) {
            return 0;
        }

        return $this->processPush($commands);
    }

    /**
     * Advertise refs to the client.
     *
     * @param array<string, string> $refs  ref => hash
     */
    public function advertiseRefs(array $refs): void
    {
        // Send capabilities
        $caps = 'report-status|report-status-v2 delete-refs side-band-64k';
        $firstRef = \key($refs) ?? 'refs/heads/main';
        $firstHash = \current($refs) ?: \str_repeat('0', 40);

        $this->writePacket("{$firstHash} {$firstRef}\x00 {$caps}");

        foreach ($refs as $ref => $hash) {
            if ($ref === $firstRef) continue;
            $this->writePacket("{$hash} {$ref}");
        }

        $this->writePacket('');  // flush
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Read push commands from stdin.
     *
     * @return list<array{old: string, new: string, ref: string}>  Packed refs
     */
    private function readCommands(): array
    {
        $commands = [];

        while (($line = \fgets(\STDIN)) !== false) {
            $line = \rtrim($line, "\r\n");
            if ($line === '') break;

            // format: old_hash new_hash ref\n
            $parts = \preg_split('/\s+/', $line);
            if (\count($parts) < 3) continue;

            [$oldHash, $newHash, $ref] = $parts;

            // Keep all commands: create (old=0), update, and delete (new=0)
            if (\strlen($oldHash) === 40 && \strlen($newHash) === 40) {
                $commands[] = ['old' => $oldHash, 'new' => $newHash, 'ref' => $ref];
            }
        }

        return $commands;
    }

    /**
     * Execute a git push.
     *
     * @param list<array{old: string, new: string, ref: string}> $commands
     */
    private function processPush(array $commands): int
    {
        $repoPath = \escapeshellarg($this->repo->path());

        foreach ($commands as $cmd) {
            $oldHash = $cmd['old'];
            $newHash = $cmd['new'];
            $ref     = $cmd['ref'];
            $escapedRef = \escapeshellarg($ref);

            // Validate hash format
            if (!\ctype_xdigit(\ltrim($newHash, '0')) && $newHash !== \str_repeat('0', 40)) {
                $this->reportError("invalid new object name: {$newHash}");
                return 1;
            }

            $out = [];
            $rc  = 0;

            if ($newHash === \str_repeat('0', 40)) {
                // Delete: git update-ref -d <ref> <old>
                $escapedOld = \escapeshellarg($oldHash);
                $updateRefCmd = "git -C {$repoPath} update-ref -d {$escapedRef} {$escapedOld} 2>&1";
                \exec($updateRefCmd, $out, $rc);
            } elseif ($oldHash === \str_repeat('0', 40)) {
                // Create: git update-ref <ref> <new> (no old arg)
                $escapedNew = \escapeshellarg($newHash);
                $updateRefCmd = "git -C {$repoPath} update-ref {$escapedRef} {$escapedNew} 2>&1";
                \exec($updateRefCmd, $out, $rc);
            } else {
                // Update: git update-ref <ref> <new> <old>
                $escapedNew = \escapeshellarg($newHash);
                $escapedOld = \escapeshellarg($oldHash);
                $updateRefCmd = "git -C {$repoPath} update-ref {$escapedRef} {$escapedNew} {$escapedOld} 2>&1";
                \exec($updateRefCmd, $out, $rc);
            }

            if ($rc !== 0) {
                $this->reportError(\implode("\n", $out));
                $this->writePacket("ng {$cmd['ref']}: pre-receive hook declined");
                return 1;
            }

            $this->writePacket("ok {$cmd['ref']}");
        }

        $this->writePacket('');  // flush
        return 0;
    }

    private function reportError(string $msg): void
    {
        \fwrite(\STDERR, "candy-serve receive-pack: {$msg}\n");
    }

    /**
     * Write a pkt-line.
     */
    private function writePacket(string $data): void
    {
        if ($data === '') {
            \fwrite(\STDOUT, "0000");
            return;
        }
        $len = \strlen($data) + 4;
        $hex = \str_pad(\dechex($len), 4, '0', \STR_PAD_LEFT);
        $bin = \hex2bin($hex);
        \fwrite(\STDOUT, $bin !== false ? $bin : $hex . "\n");
        \fwrite(\STDOUT, $data . "\n");
    }
}
