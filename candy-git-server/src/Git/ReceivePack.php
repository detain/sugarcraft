<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Git;

use SugarCraft\GitServer\{AccessControl, Repo, User};

/**
 * Handles git-receive-pack requests (push) over the SSH transport.
 *
 * The push is NOT emulated: this class authorises the session and hands
 * the whole negotiation to the real `git receive-pack` child through
 * StdioPipe. The previous hand-rolled serve() read plain-text command
 * lines (real pushes are pkt-framed and followed by raw packfile bytes)
 * and moved refs to client-claimed object IDs without ever indexing the
 * pushed objects — that recipe corrupts repositories. With delegation the
 * corruption is structurally impossible: git unpacks and verifies every
 * pushed object BEFORE any ref advances, runs the server-side hooks, and
 * enforces ref grammar and compare-and-swap semantics natively.
 *
 * CHILD-PROCESS LIFETIME: StdioPipe::run is request-scoped by
 * construction — every exit path funnels through its reap ladder
 * (SIGTERM, then SIGKILL after a grace poll). This class holds no child
 * across calls.
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
     * Serve one git-receive-pack session over the given stdio pair.
     *
     * Defaults match the SSH forced-command shape (client connection on
     * the process's own STDIN/STDOUT); injectable streams keep hosts and
     * tests in control of the byte flow.
     *
     * @param resource|\Socket|null $in  client read side (default STDIN)
     * @param resource|\Socket|null $out client write side (default STDOUT)
     */
    public function serve($in = null, $out = null): int
    {
        $ac = AccessControl::instance();

        if (!$ac->canWrite($this->user, $this->repo)) {
            $viewer = $this->user?->username ?? 'anonymous';
            \fwrite(\STDERR, "Access denied: {$viewer} cannot push to {$this->repo->name}\n");
            return 1;
        }

        // On-demand creation stays (a push to a brand-new repo is how
        // 'create on first push' works); init lays down the repository the
        // delegated child will operate on.
        if (!\is_dir($this->repo->gitDir())) {
            $this->repo = $this->repo->init();
        }

        $gitDir = $this->repo->gitDir();

        return StdioPipe::run(
            $in ?? \STDIN,
            $out ?? \STDOUT,
            ['git', '-C', $gitDir, 'receive-pack', $gitDir],
            $gitDir
        );
    }
}
