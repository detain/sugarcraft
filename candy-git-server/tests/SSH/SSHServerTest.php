<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\SSH;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\{AccessControl, Config, Repo, SSH\SSHServer, User};

/**
 * @covers \SugarCraft\GitServer\SSH\SSHServer
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

 public function testHandleConnectionRejectsWrongPresentedKey(): void
 {
 $authorized = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';
 $wrong = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk mallory@host';

 $user = User::new('alice')->withAdmin(true)->addAuthorizedKey($authorized);
 $this->server->registerUser($user);

 $stream = \fopen('php://memory', 'r+');
 // Admin push would succeed (see testCommandRegexParsesReceivePack), so a
 // failure here can only come from key verification.
 $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /key-check-repo', $wrong);
 $this->assertSame(1, $result);
 $this->assertNull($this->server->authenticatedUser());
 }

 /**
 * A duplex temp file pre-seeded with an empty push request (the ASCII
 * flush packet '0000' = a valid command list of zero commands).
 *
 * PHP's stream_select cannot represent php://memory streams, and the
 * delegated stdio pump drives its event loop through select - a memory
 * handle that ever reaches the pump stalls it to the idle deadline and
 * the child's exit code is lost. Connection tests that may spawn a git
 * child therefore use files, never memory streams.
 *
 * @return array{0: resource, 1: string}
 */
 private function flushRequest(string $tag): array
 {
 $file = $this->tmpDir . '/' . $tag . '.duplex';
 \file_put_contents($file, '0000');

 // 'r+b' not 'w+b': fopen's 'w' mode would truncate the seeded request.
 return [\fopen($file, 'r+b'), $file];
 }

 /** A registered, real-on-disk bare repo so the service never touches the shared default data dir. */
 private function registerBareRepo(string $name): Repo
 {
 $path = $this->tmpDir . '/repos/' . $name;
 $repo = Repo::new($name, $path)->init();
 $this->server->registerRepo($repo);

 return $repo;
 }

 public function testHandleConnectionAcceptsMatchingPresentedKey(): void
 {
 $authorized = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';

 $user = User::new('alice')->withAdmin(true)->addAuthorizedKey($authorized);
 $this->server->registerUser($user);
 $this->registerBareRepo('key-check-repo');

 [$stream] = $this->flushRequest('key-check');
 $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /key-check-repo', $authorized);
 // rc 0 is now git's own answer to an empty command list, measured
 // against the real receive-pack binary. The auth discriminator is
 // the identity below; a wrong key fails earlier with rc 1 and never
 // spawns the child (see the rejection pins).
 $this->assertSame(0, $result);
 $this->assertSame('alice', $this->server->authenticatedUser()?->username);
 }

 // -------------------------------------------------------------------------
 // Empty/null-key auth-bypass regression tests
 //
 // A known username plus a null / empty / whitespace-only presented key
 // must NEVER authenticate as that user. authenticatedUser() stays null and
 // handleConnection() returns non-zero — the peer is treated as having
 // supplied no valid credential, not as $user.
 // -------------------------------------------------------------------------

 private const ALICE_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';

 public function testNullPresentedKeyDoesNotAuthenticateAsUser(): void
 {
 $user = User::new('alice')->withAdmin(true)->addAuthorizedKey(self::ALICE_KEY);
 $this->server->registerUser($user);

 $stream = \fopen('php://memory', 'r+');
 // No key material at all — must not be trusted as alice.
 $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /null-key-repo', null);

 $this->assertSame(1, $result);
 $this->assertNull($this->server->authenticatedUser());
 }

 public function testEmptyStringPresentedKeyDoesNotAuthenticateAsUser(): void
 {
 $user = User::new('alice')->withAdmin(true)->addAuthorizedKey(self::ALICE_KEY);
 $this->server->registerUser($user);

 $stream = \fopen('php://memory', 'r+');
 // Empty string key is the concrete bypass — must be rejected.
 $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /empty-key-repo', '');

 $this->assertSame(1, $result);
 $this->assertNull($this->server->authenticatedUser());
 }

 public function testWhitespacePresentedKeyDoesNotAuthenticateAsUser(): void
 {
 $user = User::new('alice')->withAdmin(true)->addAuthorizedKey(self::ALICE_KEY);
 $this->server->registerUser($user);

 $stream = \fopen('php://memory', 'r+');
 // Whitespace-only key is a blank credential — must be rejected.
 $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /ws-key-repo', " \t \n ");

 $this->assertSame(1, $result);
 $this->assertNull($this->server->authenticatedUser());
 }

 // -------------------------------------------------------------------------
 // Command parsing tests
 // -------------------------------------------------------------------------

 public function testCommandRegexParsesUploadPack(): void
 {
 $stream = \fopen('php://memory', 'r+');
 $user = User::new('alice')->withAdmin(true);
 $this->server->registerUser($user);

 // Well-formed command passes the parse and dies at the repo-lookup
 // gate, before any child could spawn (a memory stream is therefore
 // safe here). The name is unregistered AND absent on disk, so no
 // leftover repository from another run can shift the outcome.
 $result = $this->server->handleConnection($stream, 'alice', 'git-upload-pack /parse-check-ghost-' . \uniqid());
 $this->assertSame(1, $result);
 }

 public function testCommandRegexParsesReceivePack(): void
 {
 // Authenticating as a user now requires a real, verified key (an
 // empty key can never authenticate as a user), so present alice's key.
 $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';
 $user = User::new('alice')->withAdmin(true)->addAuthorizedKey($key);
 $this->server->registerUser($user);
 $this->registerBareRepo('valid-name');

 [$stream] = $this->flushRequest('receive-parse');
 // Delegated to the real receive-pack child with an empty command
 // list: the parse + auth + routing chain completes with rc 0.
 $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /valid-name', $key);
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

 // Try to push to a brand-new repo - must fail without the create
 // permission, before any child spawns. Name unregistered AND absent
 // so no leftover repository on disk can soften the outcome.
 $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /create-permission-check-' . \uniqid());
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
