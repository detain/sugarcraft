<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Git;

use SugarCraft\GitServer\{AccessControl, Repo, User};

/**
 * Handles git-upload-pack requests (clone / fetch) over the SSH transport.
 *
 * The negotiation is NOT emulated: this class authorises the session and
 * then delegates it whole to the real `git upload-pack` child through
 * StdioPipe. The previous hand-rolled serve() read plain-text "want"
 * lines from STDIN before answering and emitted an advertisement with its
 * own packet framing — no conformant git client could speak to it,
 * because real clients wait for the advertisement and send pkt-framed
 * wants. Delegating removes that whole failure surface: framing,
 * capability grammar and pack construction are git's own business now.
 *
 * CHILD-PROCESS LIFETIME: StdioPipe::run is request-scoped by
 * construction — every exit path funnels through its reap ladder
 * (SIGTERM, then SIGKILL after a grace poll). This class holds no child
 * across calls.
 *
 * Port of charmbracelet/soft-serve Git.UploadPack.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class UploadPack
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
     * Serve one git-upload-pack session over the given stdio pair.
     *
     * The defaults are the SSH forced-command shape: git invokes
     * `git-upload-pack '<path>'` with the client connection already on the
     * process's own STDIN/STDOUT. Injecting streams keeps tests (and any
     * other host that holds the connection as a resource) in control of
     * where the bytes flow.
     *
     * @param resource|\Socket|null $in  client read side (default STDIN)
     * @param resource|\Socket|null $out client write side (default STDOUT)
     */
    public function serve($in = null, $out = null): int
    {
        $ac = AccessControl::instance();

        if (!$ac->canRead($this->user, $this->repo)) {
            $viewer = $this->user?->username ?? 'anonymous';
            \fwrite(\STDERR, "Access denied: {$viewer} cannot read {$this->repo->name}\n");
            return 1;
        }

        $gitDir = $this->repo->gitDir();
        if (!\is_dir($gitDir)) {
            \fwrite(\STDERR, "Repository not found: {$this->repo->name}\n");
            return 1;
        }

        // The repository is addressed by its full path with the child's cwd
        // matching, the shape proven byte-for-byte on the daemon conformance
        // wire: a bare directory does not resolve a plain '.' argument.
        return StdioPipe::run(
            $in ?? \STDIN,
            $out ?? \STDOUT,
            ['git', '-C', $gitDir, 'upload-pack', $gitDir],
            $gitDir
        );
    }
}
