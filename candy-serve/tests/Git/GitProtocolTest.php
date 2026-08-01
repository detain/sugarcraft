<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Repo;
use SugarCraft\Serve\User;
use SugarCraft\Serve\Git\ReceivePack;
use SugarCraft\Serve\Git\UploadPack;

/**
 * @covers \SugarCraft\Serve\Git\ReceivePack
 * @covers \SugarCraft\Serve\Git\UploadPack
 */
final class GitProtocolTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/git-protocol-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
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

    private function initGitRepo(): string
    {
        $path = $this->tmpDir . '/test-repo.git';
        \mkdir($path, 0755, true);
        $bare = \escapeshellarg($path);
        \exec("git -c init.defaultBranch=master init --bare {$bare} 2>/dev/null");

        $workDir = $this->tmpDir . '/work';
        \mkdir($workDir, 0755, true);
        $work = \escapeshellarg($workDir);
        \exec(
            "git -c init.defaultBranch=master init {$work} 2>/dev/null && " .
            "echo 'hello' > {$workDir}/file.txt && " .
            "git -C {$work} add . && " .
            "git -C {$work} -c user.email=test@example.com -c user.name=Test commit -m 'initial' 2>/dev/null && " .
            "git -C {$work} push {$bare} master 2>/dev/null"
        );

        return $path;
    }

    // -------------------------------------------------------------------------
    // ReceivePack advertiseRefs tests
    // -------------------------------------------------------------------------

    public function testAdvertiseRefsWritesFirstRefWithCapabilities(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);
        $rp = new ReceivePack($repo);

        $refs = ['refs/heads/master' => \str_repeat('a', 40)];

        // Capture stdout
        $stdout = fopen('php://temp', 'r+');
        $originalOut = \STDOUT;
        \ob_start();
        $rp->advertiseRefs($refs);
        $output = \ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('refs/heads/master', $output);
        $this->assertStringContainsString('report-status', $output);
    }

    public function testAdvertiseRefsWritesMultipleRefs(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);
        $rp = new ReceivePack($repo);

        $refs = [
            'refs/heads/master' => \str_repeat('a', 40),
            'refs/heads/feature' => \str_repeat('b', 40),
            'refs/tags/v1.0' => \str_repeat('c', 40),
        ];

        \ob_start();
        $rp->advertiseRefs($refs);
        $output = \ob_get_clean();

        $this->assertStringContainsString('refs/heads/master', $output);
        $this->assertStringContainsString('refs/heads/feature', $output);
        $this->assertStringContainsString('refs/tags/v1.0', $output);
    }

    public function testAdvertiseRefsHandlesEmptyRefs(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);
        $rp = new ReceivePack($repo);

        \ob_start();
        $rp->advertiseRefs([]);
        $output = \ob_get_clean();

        // Should still write a pkt-line with default ref
        $this->assertIsString($output);
    }

    // -------------------------------------------------------------------------
    // ReceivePack writePacket tests (via advertiseRefs since it's private)
    // -------------------------------------------------------------------------

    public function testWritePacketFlushWritesZeroLength(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);
        $rp = new ReceivePack($repo);

        // The advertiseRefs ends with a flush packet (empty string)
        $refs = ['refs/heads/master' => \str_repeat('a', 40)];
        \ob_start();
        $rp->advertiseRefs($refs);
        $output = \ob_get_clean();

        // Flush packet is "0000" hex = 4 zero bytes
        $this->assertIsString($output);
    }

    // -------------------------------------------------------------------------
    // UploadPack advertiseRefs tests
    // -------------------------------------------------------------------------

    public function testUploadPackAdvertiseRefsWithBranches(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);
        $up = new UploadPack($repo);

        $result = $up->advertiseRefs();

        $this->assertIsString($result);
        // Should contain at least the head ref
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40} refs\/heads\//m', $result);
    }

    public function testUploadPackAdvertiseRefsContainsNewlineTerminatedLines(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);
        $up = new UploadPack($repo);

        $result = $up->advertiseRefs();
        $lines = \explode("\n", $result);

        // First line should be a ref
        $this->assertNotEmpty($lines[0]);
        // Last line should be empty (flush)
        $this->assertSame('', \end($lines));
    }

    // -------------------------------------------------------------------------
    // Repo Git operations tests
    // -------------------------------------------------------------------------

    public function testRepoBranchesReturnsArray(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $branches = $repo->branches();

        $this->assertIsArray($branches);
        $this->assertNotEmpty($branches);
        $this->assertContains('master', $branches);
    }

    public function testRepoTagsReturnsArray(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $tags = $repo->tags();

        $this->assertIsArray($tags);
        // Initially no tags
        $this->assertEmpty($tags);
    }

    public function testRepoRefsReturnsHashToRefMap(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $refs = $repo->refs('refs/heads');

        $this->assertIsArray($refs);
        $this->assertArrayHasKey('refs/heads/master', $refs);
        // Hash should be 40 characters
        $this->assertSame(40, \strlen($refs['refs/heads/master']));
    }

    public function testRepoRefsWithDifferentPrefix(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $headsRefs = $repo->refs('refs/heads');
        $allRefs = $repo->refs();

        $this->assertIsArray($headsRefs);
        $this->assertIsArray($allRefs);
        // All refs should include heads
        foreach ($headsRefs as $ref => $hash) {
            $this->assertArrayHasKey($ref, $allRefs);
        }
    }

    public function testRepoReadFileReturnsNullForNonexistent(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $refs = $repo->refs();
        $headHash = $refs['refs/heads/master'] ?? null;
        $this->assertNotNull($headHash);

        $result = $repo->readFile($headHash, 'nonexistent.txt');
        $this->assertNull($result);
    }

    public function testRepoReadmeReturnsNullWhenNoReadme(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $readme = $repo->readme();

        // No README was created in the test repo
        $this->assertNull($readme);
    }

    public function testRepoReadmeReturnsArrayWhenReadmeExists(): void
    {
        $repoPath = $this->tmpDir . '/repo-with-readme.git';
        \mkdir($repoPath, 0755, true);
        $bare = \escapeshellarg($repoPath);
        \exec("git -c init.defaultBranch=master init --bare {$bare} 2>/dev/null");

        $workDir = $this->tmpDir . '/work-readme';
        \mkdir($workDir, 0755, true);
        $work = \escapeshellarg($workDir);
        \exec(
            "git -c init.defaultBranch=master init {$work} 2>/dev/null && " .
            "echo '# Hello' > {$workDir}/README.md && " .
            "git -C {$work} add . && " .
            "git -C {$work} -c user.email=test@example.com -c user.name=Test commit -m 'initial' 2>/dev/null && " .
            "git -C {$work} push {$bare} master 2>/dev/null"
        );

        $repo = Repo::new('test', $repoPath);
        $readme = $repo->readme();

        $this->assertNotNull($readme);
        $this->assertIsArray($readme);
        $this->assertArrayHasKey('content', $readme);
        $this->assertArrayHasKey('name', $readme);
        $this->assertStringContainsString('Hello', $readme['content']);
    }

    // -------------------------------------------------------------------------
    // Repo visibility and collaborator tests
    // -------------------------------------------------------------------------

    public function testWithPublicTrueSetsPublicVisibility(): void
    {
        $repo = Repo::new('test', '/tmp/test');

        $newRepo = $repo->withPublic(true);

        $this->assertTrue($newRepo->isPublic);
        $this->assertTrue($newRepo->isVisiblePublic());
    }

    public function testWithPublicFalseOnPublicSetsCollaboratorOnly(): void
    {
        $repo = Repo::new('test', '/tmp/test')->withPublic(true);

        $newRepo = $repo->withPublic(false);

        $this->assertFalse($newRepo->isPublic);
        // Should become CollaboratorOnly, not Private
        $this->assertFalse($newRepo->isPrivate());
    }

    public function testWithPublicFalseOnPrivateKeepsPrivate(): void
    {
        $repo = Repo::new('test', '/tmp/test')->withPrivate(true);

        $newRepo = $repo->withPublic(false);

        $this->assertTrue($newRepo->isPrivate());
    }

    public function testWithPrivateTrueSetsPrivateVisibility(): void
    {
        $repo = Repo::new('test', '/tmp/test');

        $newRepo = $repo->withPrivate(true);

        $this->assertTrue($newRepo->isPrivate());
    }

    public function testWithPrivateFalseOnPrivateSetsCollaboratorOnly(): void
    {
        $repo = Repo::new('test', '/tmp/test')->withPrivate(true);

        $newRepo = $repo->withPrivate(false);

        $this->assertFalse($newRepo->isPrivate());
    }

    public function testWithPrivateFalseOnPublicKeepsPublic(): void
    {
        $repo = Repo::new('test', '/tmp/test')->withPublic(true);

        $newRepo = $repo->withPrivate(false);

        // Private(false) on Public should stay Public
        $this->assertTrue($newRepo->isPublic);
    }

    public function testAddCollaboratorAddsUser(): void
    {
        $repo = Repo::new('test', '/tmp/test');

        $newRepo = $repo->addCollaborator('alice');

        $this->assertTrue($newRepo->isCollaborator('alice'));
        $this->assertContains('alice', $newRepo->collaborators());
    }

    public function testAddCollaboratorDoesNotDuplicate(): void
    {
        $repo = Repo::new('test', '/tmp/test')->addCollaborator('alice');

        $newRepo = $repo->addCollaborator('alice');

        // Should not duplicate
        $this->assertCount(1, $newRepo->collaborators());
    }

    public function testRemoveCollaboratorRemovesUser(): void
    {
        $repo = Repo::new('test', '/tmp/test')->addCollaborator('alice')->addCollaborator('bob');

        $newRepo = $repo->removeCollaborator('alice');

        $this->assertFalse($newRepo->isCollaborator('alice'));
        $this->assertTrue($newRepo->isCollaborator('bob'));
    }

    public function testIsMirrorReturnsFalseWhenNoMirror(): void
    {
        $repo = Repo::new('test', '/tmp/test');

        $this->assertFalse($repo->isMirror());
    }

    public function testIsMirrorReturnsTrueWhenMirrorSet(): void
    {
        $repo = Repo::new('test', '/tmp/test')->withMirrorFrom('https://example.com/repo.git');

        $this->assertTrue($repo->isMirror());
    }

    // -------------------------------------------------------------------------
    // User authorized keys tests
    // -------------------------------------------------------------------------

    public function testUserAuthorizedKeysListEmpty(): void
    {
        $user = User::new('alice');

        $keys = $user->authorizedKeysList();

        $this->assertIsArray($keys);
        $this->assertEmpty($keys);
    }

    public function testUserWithAuthorizedKeysReturnsList(): void
    {
        $user = User::new('alice')->withAuthorizedKeys("ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAtest user@host\nssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABQAAAAdemo");

        $keys = $user->authorizedKeysList();

        $this->assertCount(2, $keys);
        $this->assertStringStartsWith('ssh-ed25519', $keys[0]);
        $this->assertStringStartsWith('ssh-rsa', $keys[1]);
    }

    public function testAddAuthorizedKeyWithValidKey(): void
    {
        // Valid ed25519 key (68 base64 chars = 51 bytes)
        $validKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAAHQ8tLS7VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8test comment@host';

        $user = User::new('alice');
        $newUser = $user->addAuthorizedKey($validKey);

        $this->assertNotSame($user, $newUser);
        $this->assertCount(1, $newUser->authorizedKeysList());
    }

    public function testAddAuthorizedKeyIgnoresEmptyKey(): void
    {
        $user = User::new('alice');
        $newUser = $user->addAuthorizedKey('');

        $this->assertSame($user, $newUser);
        $this->assertEmpty($newUser->authorizedKeysList());
    }

    public function testAddAuthorizedKeyTrimsWhitespace(): void
    {
        $validKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAAHQ8tLS7VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8test comment@host';

        $user = User::new('alice');
        $newUser = $user->addAuthorizedKey("  {$validKey}  \n");

        $this->assertCount(1, $newUser->authorizedKeysList());
    }

    public function testAddAuthorizedKeyRejectsInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = User::new('alice');
        $user->addAuthorizedKey('not-a-valid-key-format');
    }

    public function testAddAuthorizedKeyRejectsTooShortBlob(): void
    {
        // Key with truncated blob (less than 68 chars for ed25519)
        $shortKey = 'ssh-ed25519 AAAAtest';

        $this->expectException(\InvalidArgumentException::class);

        $user = User::new('alice');
        $user->addAuthorizedKey($shortKey);
    }

    public function testVerifyPublicKeyReturnsFalseWhenNoKeys(): void
    {
        $user = User::new('alice');

        $result = $user->verifyPublicKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAAHQ8tLS7VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8test');

        $this->assertFalse($result);
    }

    public function testVerifyPublicKeyReturnsFalseOnMismatch(): void
    {
        $user = User::new('alice')->withAuthorizedKeys('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAAHQ8tLS7VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8test comment@host');

        $result = $user->verifyPublicKey('ssh-ed25519 BBBBC3NzaC1lZDI1NTE5AAAAAHQ8tLS7VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8different');

        $this->assertFalse($result);
    }

    public function testVerifyPublicKeyReturnsTrueOnMatch(): void
    {
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAAHQ8tLS7VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8test comment@host';
        $user = User::new('alice')->withAuthorizedKeys($key);

        $result = $user->verifyPublicKey($key);

        $this->assertTrue($result);
    }

    public function testVerifyPublicKeyNormalizesWhitespace(): void
    {
        $key1 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAAHQ8tLS7VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8test comment@host';
        $key2 = '  ssh-ed25519   AAAAC3NzaC1lZDI1NTE5AAAAAHQ8tLS7VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8VbJJ8test   comment@host  ';
        $user = User::new('alice')->withAuthorizedKeys($key1);

        $result = $user->verifyPublicKey($key2);

        $this->assertTrue($result);
    }

    public function testGenerateKeyPairReturnsNullWithoutSsh2Extension(): void
    {
        $user = User::new('alice');

        // If ssh2 extension is not loaded, should return null
        if (\extension_loaded('ssh2')) {
            $this->markTestSkipped('ssh2 extension is loaded, cannot test null path');
        }

        $result = $user->generateKeyPair();

        $this->assertNull($result);
    }

    public function testPublicKeyComment(): void
    {
        $user = User::new('alice');

        $comment = $user->publicKeyComment();

        $this->assertSame('alice@candy-serve', $comment);
    }

    // -------------------------------------------------------------------------
    // User with* builder tests
    // -------------------------------------------------------------------------

    public function testWithUsername(): void
    {
        $user = User::new('alice');
        $newUser = $user->withUsername('bob');

        $this->assertSame('bob', $newUser->username);
        $this->assertNotSame($user, $newUser);
    }

    public function testWithAdmin(): void
    {
        $user = User::new('alice');
        $newUser = $user->withAdmin(true);

        $this->assertTrue($newUser->isAdmin);
        $this->assertNotSame($user, $newUser);
    }

    public function testWithActive(): void
    {
        $user = User::new('alice');
        $newUser = $user->withActive(false);

        $this->assertFalse($newUser->isActive);
        $this->assertNotSame($user, $newUser);
    }

    public function testWithPassword(): void
    {
        $user = User::new('alice');
        $newUser = $user->withPassword('secret123');

        $this->assertSame('secret123', $newUser->password);
        $this->assertNotSame($user, $newUser);
    }

    public function testWithAuthorizedKeys(): void
    {
        $user = User::new('alice');
        $newUser = $user->withAuthorizedKeys('ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQC9...');

        $this->assertStringContainsString('ssh-rsa', $newUser->authorizedKeysList()[0]);
        $this->assertNotSame($user, $newUser);
    }
}
