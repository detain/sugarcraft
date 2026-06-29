<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Git;

use SugarCraft\Serve\{AccessControl, Repo, User};

/**
 * Handles git-upload-pack requests (clone / fetch).
 *
 * Implements the Git SSH protocol for read operations.
 *
 * Port of charmbracelet/soft-serve Git.UploadPack.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class UploadPack
{
    private Repo $repo;
    private ?User $user;
    private string $wantedRefs = '';
    private string $wantRefs   = '';

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
     * Serve a git-upload-pack session over stdio.
     *
     * Reads the upload-pack request from stdin, outputs the packfile response.
     * Designed to be called by SSH forced-command or CGI.
     */
    public function serve(): int
    {
        $ac = AccessControl::getInstance();

        if (!$ac->canRead($this->user, $this->repo)) {
            $viewer = $this->user?->username ?? 'anonymous';
            \fwrite(\STDERR, "Access denied: {$viewer} cannot read {$this->repo->name}\n");
            return 1;
        }

        if (!$this->repo->exists()) {
            \fwrite(\STDERR, "Repository not found: {$this->repo->name}\n");
            return 1;
        }

        // Read want refs from client
        $wants = $this->readWants();
        if ($wants === []) {
            \fwrite(\STDERR, "No wants received\n");
            return 1;
        }

        $this->sendRefs();
        $this->sendPack($wants);

        return 0;
    }

    /**
     * Build refs advertisement packet.
     */
    public function advertiseRefs(): string
    {
        $lines = [];

        // Head ref
        $branches = $this->repo->branches();
        $head = $branches !== [] ? $branches[0] : 'main';
        $headHash = $this->repo->refs("refs/heads/{$head}")["refs/heads/{$head}"] ?? '';
        $lines[] = "{$headHash} refs/heads/{$head}";

        // All other refs
        foreach ($this->repo->refs() as $ref => $hash) {
            if ($ref !== 'refs/heads/' . $head) {
                $lines[] = "{$hash} {$ref}";
            }
        }

        $lines[] = '';  // flush

        return \implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Read git-upload-pack wants from stdin.
     *
     * @return list<string>  Commit hashes wanted by client
     */
    private function readWants(): array
    {
        $wants = [];

        while (($line = \fgets(\STDIN)) !== false) {
            $line = \rtrim($line, "\r\n");
            if ($line === '') break;

            // git-upload-pack sends "want HASH\n"
            if (\str_starts_with($line, 'want ')) {
                $hash = \substr($line, 5);
                if (\strlen($hash) === 40 && \ctype_xdigit($hash)) {
                    $wants[] = $hash;
                }
            }
        }

        // Discard have lines
        while (($line = \fgets(\STDIN)) !== false) {
            $line = \rtrim($line, "\r\n");
            if ($line === 'done') break;
        }

        return $wants;
    }

    /**
     * Send the refs advertisement to the client.
     */
    private function sendRefs(): void
    {
        $packet = $this->advertiseRefs();
        $this->writePacket($packet);
    }

    /**
     * Build and send a packfile for the given wants.
     *
     * @param list<string> $wants  Commit hashes
     */
    private function sendPack(array $wants): void
    {
        if ($wants === []) return;

        $repoPath = \escapeshellarg($this->repo->path());
        $cmd = "git -C {$repoPath} pack-objects --stdout --revs 2>/dev/null";

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $desc, $pipes);
        if ($proc === false) return;

        // Write want revs to stdin (one per line, no ^ prefix)
        foreach ($wants as $hash) {
            \fwrite($pipes[0], $hash . "\n");
        }
        \fclose($pipes[0]);

        // Stream pack data to stdout
        while (!\feof($pipes[1])) {
            $chunk = \fread($pipes[1], 65536);
            if ($chunk === false) break;
            \fwrite(\STDOUT, $chunk);
        }
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);
    }

    /**
     * Write a pkt-line (Git wire protocol).
     */
    private function writePacket(string $data): void
    {
        $len = \strlen($data) + 4;  // 4 bytes for length prefix
        $hex = \dechex($len);
        $hex = \str_pad($hex, 4, '0', \STR_PAD_LEFT);
        \fwrite(\STDOUT, \hex2bin($hex) ?: $hex . "\n");
        \fwrite(\STDOUT, $data . "\n");
    }
}
