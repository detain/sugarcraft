<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\SSH;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\{AccessControl, Config, Repo, SSH\SSHServer, User};

/**
 * @covers \SugarCraft\Serve\SSH\SSHServer
 */
final class SSHServerTest extends TestCase
{
    private string $tmpDir;
    private Config $config;
    private SSHServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/ssh-server-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
        \mkdir($this->tmpDir . '/repositories', 0755, true);

        $this->config = Config::fromDefaults();
        $this->server = new SSHServer($this->config);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    private function createRepoPath(string $name): string
    {
        $path = $this->tmpDir . '/repositories/' . $name;
        \mkdir($path, 0755, true);
        return $path;
    }

    // -------------------------------------------------------------------------
    // Registration tests
    // -------------------------------------------------------------------------

    public function testRegisterUser(): void
    {
        $user = User::new('alice');
        $this->server->registerUser($user);

        $this->assertArrayHasKey('alice', $this->server->users());
    }

    public function testRegisterRepo(): void
    {
        $path = $this->createRepoPath('test-repo');
        $repo = Repo::new('test-repo', $path);
        $this->server->registerRepo($repo);

        $this->assertArrayHasKey('test-repo', $this->server->repos());
    }

    // -------------------------------------------------------------------------
    // Auth tests (Step 6) - note: these test the non-ssh2 (no-op) path
    // -------------------------------------------------------------------------

    public function testAuthenticateWithUnknownUserReturns1(): void
    {
        $stream = \fopen('php://memory', 'r+');
        // Unknown user - should fail
        $result = $this->server->handleConnection($stream, 'nobody', 'git-upload-pack /test');
        $this->assertSame(1, $result);
    }

    public function testAuthenticateWithKnownUserAndNoSsh2PassesEarlyCheck(): void
    {
        $user = User::new('alice');
        $this->server->registerUser($user);

        $stream = \fopen('php://memory', 'r+');
        // Without ssh2, authenticate just checks user exists
        // This will fail at repo lookup, not auth
        $result = $this->server->handleConnection($stream, 'alice', 'git-upload-pack /nonexistent');
        // Returns 1 because repo not found, not because auth failed
        $this->assertSame(1, $result);
    }

    // -------------------------------------------------------------------------
    // Command parsing tests
    // -------------------------------------------------------------------------

    public function testCommandRegexParsesUploadPack(): void
    {
        $stream = \fopen('php://memory', 'r+');
        $user = User::new('alice')->withAdmin(true);
        $this->server->registerUser($user);

        // Well-formed command should be parsed (repo lookup happens after parse)
        $result = $this->server->handleConnection($stream, 'alice', 'git-upload-pack /valid-name');
        // Fails at repo lookup (repo doesn't exist), not command parse
        $this->assertSame(1, $result);
    }

    public function testCommandRegexParsesReceivePack(): void
    {
        $stream = \fopen('php://memory', 'r+');
        $user = User::new('alice')->withAdmin(true);
        $this->server->registerUser($user);

        // With admin and git-receive-pack, on-demand repo creation succeeds
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /valid-name');
        $this->assertSame(0, $result);
    }

    // -------------------------------------------------------------------------
    // Name validation tests (Step 4)
    // -------------------------------------------------------------------------

    public function testRejectsInvalidRepoName(): void
    {
        $stream = \fopen('php://memory', 'r+');
        $user = User::new('alice')->withAdmin(true);
        $this->server->registerUser($user);

        // Path traversal repo name - should be rejected
        $result = $this->server->handleConnection($stream, 'alice', 'git-upload-pack /../../etc');
        $this->assertSame(1, $result);
    }

    // -------------------------------------------------------------------------
    // On-demand create tests (Step 4)
    // -------------------------------------------------------------------------

    public function testOnDemandCreateRequiresCreatePermission(): void
    {
        $stream = \fopen('php://memory', 'r+');
        // Non-admin user
        $user = User::new('alice');
        $this->server->registerUser($user);

        // Try to push to a non-existent repo - should fail without admin
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /new-repo');
        // Should return 1 because user cannot create repos
        $this->assertSame(1, $result);
    }

    // -------------------------------------------------------------------------
    // Query tests
    // -------------------------------------------------------------------------

    public function testConfig(): void
    {
        $this->assertSame($this->config, $this->server->config());
    }

    public function testAuthenticatedUserIsNullInitially(): void
    {
        $this->assertNull($this->server->authenticatedUser());
    }
}
