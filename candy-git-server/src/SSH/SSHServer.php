<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\SSH;

use SugarCraft\GitServer\Lang;
use SugarCraft\GitServer\{AccessControl, Config, Repo, User};

/**
 * Git-over-SSH service dispatcher for a host sshd ForceCommand wiring.
 *
 * This class does NOT implement the SSH protocol itself — the operating
 * system's sshd authenticates the key and forces
 * `git-upload-pack '<path>'` / `git-receive-pack '<path>'` into a php
 * child whose STDIN/STDOUT is the client connection. The child calls
 * handleConnection() with those stdio streams; this class checks the
 * account, the jail, and repository permissions, then hands the session
 * to the delegated git services. The presented public key (whatever the
 * host wrapper can supply) is verified against the account's
 * authorized_keys before anything else runs.
 *
 * Port of charmbracelet/soft-serve SSHServer.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class SSHServer
{
    private Config $config;

    /** @var array<string, User> username => User */
    private array $users = [];

    /** @var array<string, Repo> repo name => Repo */
    private array $repos = [];

    private ?User $authenticatedUser = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function registerUser(User $user): void
    {
        $this->users[$user->username] = $user;
    }

    public function registerRepo(Repo $repo): void
    {
        // Registration is state the operator means to survive the process:
        // a PUBLIC registration persists its visibility marker (idempotent,
        // exists()-gated), exactly as GitDaemon::registerRepo does, so the
        // repo stays published for the next boot’s marker-honest fallback.
        if ($repo->exists() && $repo->isVisiblePublic()) {
            $repo->writeVisibilityMarker();
        }

        $this->repos[$repo->name] = $repo;
    }

    /** @param iterable<User> $users */
    public function setUsers(iterable $users): void
    {
        $this->users = [];
        foreach ($users as $user) {
            $this->users[$user->username] = $user;
        }
    }

    // -------------------------------------------------------------------------
    // SSH session handling
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming SSH connection.
     *
     * This is the main entry point for an SSH session. It:
     * 1. Authenticates the user via public key
     * 2. Parses the Git command from the SSH forced command
     * 3. Routes to UploadPack or ReceivePack
     *
     * @param resource|\Socket $stream  The client connection: the forced
     *                                  command's STDIN/STDOUT pair in real
     *                                  use, or any duplex stream. It is
     *                                  threaded into the handler — the
     *                                  session's bytes flow through this
     *                                  stream, not the constants.
     * @param string $username  SSH username
     * @param string $command   The forced command (e.g. "git-upload-pack /repo-name")
     * @param string|null $publicKey  The public key presented during the SSH
     *                                handshake (authorized_keys format), when
     *                                the transport can supply it
     * @return int  Exit code (0 = ok)
     */
    public function handleConnection($stream, string $username, string $command, ?string $publicKey = null): int
    {
        // Find user
        $user = $this->users[$username] ?? null;

        if ($user !== null && !$user->isActive) {
            \fwrite(\STDERR, "User " . self::logSafe($username) . " is deactivated\n");
            return 1;
        }

        // Handle public key auth
        if (!$this->authenticate($stream, $user, $publicKey)) {
            \fwrite(\STDERR, "Authentication failed for " . self::logSafe($username) . "\n");
            return 1;
        }

        $this->authenticatedUser = $user;

        // Parse git command
        if (!\preg_match('/^(git-upload-pack|git-receive-pack)\s+["\']?(\/[^"\'\s]+)["\']?/', $command, $m)) {
            \fwrite(\STDERR, "Unknown command: " . self::logSafe($command) . "\n");
            return 1;
        }

        $gitCmd = $m[1];
        $path   = \rtrim($m[2], '/');
        $repoName = \basename($path);  // e.g. "/repos/foo.git" -> "foo.git"

        // Validate repo name to prevent path traversal: basename() of '/..'
        // is '..' and the old charset gate let it through, addressing the
        // parent of the repos root (and creating repos there on push).
        // isJailSafeName rejects the all-dots names that the charset allows.
        if (!Repo::isJailSafeName($repoName)) {
            \fwrite(\STDERR, "Invalid repo name: " . self::logSafe($repoName) . "\n");
            return 1;
        }

        // Find repo
        $repo = $this->repos[$repoName]
             ?? $this->findRepoByPath($path);

        if ($repo === null) {
            $ac = AccessControl::getInstance();
            // Allow on-demand repo creation for authenticated users who can create repos
            if ($gitCmd === 'git-receive-pack' && $ac->canCreateRepos($user)) {
                $repo = $this->createRepo($repoName, $user);
            } else {
                \fwrite(\STDERR, "Repository not found: {$repoName}\n");
                return 1;
            }
        }

        // Route to Git protocol handler
        if ($gitCmd === 'git-upload-pack') {
            $handler = new \SugarCraft\GitServer\Git\UploadPack($repo, $user);
        } else {
            $handler = new \SugarCraft\GitServer\Git\ReceivePack($repo, $user);
        }

        // One connection, both directions: the delegated git child speaks to
        // this stream, so the bytes never touch the process constants here.
        return $handler->serve($stream, $stream);
    }

    /**
     * Neutralize a client-claimed token for the operator log. The username
     * (and the refused command/repo shapes) arrive straight from the peer:
     * a CR/LF inside a claimed name forges additional STDERR lines, and an
     * unbounded one floods the log. Strip C0 controls and DEL, collapse
     * whitespace runs, and clip. Identity on any legitimate name — the
     * account lookup above still uses the raw token, so sanitizing here
     * cannot alias a real account.
     */
    private static function logSafe(string $token): string
    {
        $clean = \preg_replace('/[\x00-\x1F\x7F]+/', ' ', $token) ?? '';
        $clean = \trim($clean);
        if (\strlen($clean) > 64) {
            // Byte clip, no marker: ext-mbstring is not a declared
            // dependency, and appending an ellipsis could split a
            // multi-byte sequence already inside the kept prefix.
            $clean = \substr($clean, 0, 64);
        }
        return $clean === '' ? '<empty>' : $clean;
    }

    /**
     * Authenticate via SSH public key.
     *
     * In a real SSH server, the key verification is done by libssh2 during
     * the SSH handshake. This method verifies that the presented key matches
     * the user's authorized_keys.
     *
     * @param resource $stream  SSH stream
     * @param User|null $user   The user to verify against (null = anonymous)
     * @param string|null $presentedKey  Peer public key from the handshake, if available
     */
    private function authenticate($stream, ?User $user, ?string $presentedKey = null): bool
    {
        if ($user === null) {
            // Anonymous access — allowed only if server permits it
            $ac = AccessControl::getInstance();
            return \extension_loaded('ssh2') && $ac->allowAnonymousRead();
        }

        // A known username is not proof of identity: authenticating AS a user
        // requires a real, non-empty public key that matches their
        // authorized_keys. A null / empty / whitespace-only key is never a
        // valid credential — treating it as one let a peer that presented no
        // key be trusted as $user (auth bypass). Reject it outright: an empty
        // key can never authenticate as a user.
        if ($presentedKey === null || \trim($presentedKey) === '') {
            return false;
        }

        return $user->verifyPublicKey($presentedKey);
    }

    /**
     * Find a repo by its filesystem path: registered instances first, then
     * a disk fallback that carries the on-disk visibility marker — an
     * unregistered directory resolves fail-closed PRIVATE, never
     * public-by-presence.
     */
    private function findRepoByPath(string $path): ?Repo
    {
        // Try exact match in registered repos
        foreach ($this->repos as $repo) {
            if ($repo->path() === $path) {
                return $repo;
            }
        }

        // Try to find by name in repos dir
        $name = \basename($path);
        $fullPath = $this->config->reposPath() . '/' . $name;

        // gitDirFor() resolves the layout the same way the marker writer
        // does — a true bare directory (HEAD at root) keeps its marker at
        // its own root, so a hardcoded '/.git' probe would miss the
        // publication an operator persisted via registerRepo.
        $gitDir = Repo::gitDirFor($fullPath);
        if (\is_dir($gitDir)) {
            $repo = Repo::new($name, $fullPath)
                ->withVisibility(Repo::markerVisibility($gitDir));
            $this->repos[$name] = $repo;
            return $repo;
        }

        return null;
    }

    /**
     * Create a new repo on demand.
     */
    private function createRepo(string $name, ?User $user): Repo
    {
        if (!Repo::isJailSafeName($name)) {
            // Defence in depth: every caller gates first, but this method is
            // what actually touches the filesystem.
            throw new \RuntimeException(Lang::t('ssh.invalid_repo_name', ['name' => $name]));
        }

        $ac = AccessControl::getInstance();
        if (!$ac->canCreateRepos($user)) {
            $viewer = $user?->username;
            throw new \RuntimeException(Lang::t('ssh.user_cannot_create', ['viewer' => $viewer]));
        }

        $path = $this->config->reposPath() . '/' . $name;
        // Created private. A push-creates-repo path must never also make that
        // repo world-readable: publishing is an explicit operator act
        // (`repo create --public` writes the visibility marker), not a
        // side effect of the first push.
        $repo = Repo::new($name, $path)->init();

        $this->repos[$name] = $repo;

        \fwrite(\STDERR, "Created new repository: {$name}\n");
        return $repo;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function config(): Config
    {
        return $this->config;
    }

    /** @return array<string, User> */
    public function users(): array
    {
        return $this->users;
    }

    /** @return array<string, Repo> */
    public function repos(): array
    {
        return $this->repos;
    }

    public function authenticatedUser(): ?User
    {
        return $this->authenticatedUser;
    }
}
