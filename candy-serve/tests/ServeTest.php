<?php

declare(strict_types=1);

namespace CandyCore\Serve\Tests;

use CandyCore\Serve\{AccessControl, Config, Repo, User};
use PHPUnit\Framework\TestCase;

final class ServeTest extends TestCase
{
    // ---- Config tests ----

    public function testConfigFromDefaults(): void
    {
        $c = Config::fromDefaults();
        $this->assertSame('CandyServe', $c->name);
        $this->assertSame(':23231', $c->sshListenAddr);
        $this->assertSame(':9418', $c->gitListenAddr);
        $this->assertSame(':23232', $c->httpListenAddr);
        $this->assertSame('sqlite', $c->dbDriver);
        $this->assertTrue($c->lfsEnabled);
    }

    public function testConfigReposPath(): void
    {
        $c = Config::fromDefaults();
        $p = $c->reposPath();
        $this->assertStringContainsString('repositories', $p);
    }

    // ---- User tests ----

    public function testUserNew(): void
    {
        $u = User::new('alice');
        $this->assertSame('alice', $u->username);
        $this->assertFalse($u->isAdmin);
        $this->assertTrue($u->isActive);
    }

    public function testUserWithAdmin(): void
    {
        $u = User::new('bob')->withAdmin();
        $this->assertTrue($u->isAdmin);
    }

    public function testUserAddAuthorizedKey(): void
    {
        $u = User::new('carol')
            ->addAuthorizedKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk carol@host');

        $keys = $u->authorizedKeysList();
        $this->assertCount(1, $keys);
        $this->assertStringContainsString('ssh-ed25519', $keys[0]);
    }

    public function testUserAddInvalidKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        User::new('dave')->addAuthorizedKey('not-a-valid-ssh-key');
    }

    public function testUserVerifyPublicKey(): void
    {
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk carol@host';
        $u    = User::new('carol')->addAuthorizedKey($key);

        $this->assertTrue($u->verifyPublicKey($key));
        $this->assertFalse($u->verifyPublicKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk wrong@host'));
        $this->assertFalse($u->verifyPublicKey('ssh-rsa AAAAB3NzaC1...'));
    }

    public function testUserImmutability(): void
    {
        $a = User::new('x');
        $b = $a->withAdmin(true);
        $this->assertFalse($a->isAdmin);
        $this->assertTrue($b->isAdmin);
    }

    // ---- Repo tests ----

    public function testRepoNew(): void
    {
        $r = Repo::new('test-repo', '/tmp/test-repo');
        $this->assertSame('test-repo', $r->name);
        $this->assertSame('/tmp/test-repo', $r->path());
        $this->assertTrue($r->isPublic);
    }

    public function testRepoWithPublicPrivate(): void
    {
        $r = Repo::new('test', '/tmp/t')->withPublic(false)->withPrivate(true);
        $this->assertFalse($r->isPublic);
        $this->assertTrue($r->isPrivate());
    }

    public function testRepoAddCollaborator(): void
    {
        $r = Repo::new('collaborators-test', '/tmp/ct')
            ->addCollaborator('alice')
            ->addCollaborator('bob');

        $this->assertTrue($r->isCollaborator('alice'));
        $this->assertTrue($r->isCollaborator('bob'));
        $this->assertFalse($r->isCollaborator('carol'));
    }

    public function testRepoCollaboratorDeduplication(): void
    {
        $r = Repo::new('dedup', '/tmp/d')
            ->addCollaborator('alice')
            ->addCollaborator('alice');

        $this->assertCount(1, $r->collaborators());
    }

    public function testRepoWithMirrorFrom(): void
    {
        $r = Repo::new('mirror-test', '/tmp/mt')
            ->withMirrorFrom('https://github.com/example/repo.git');

        $this->assertSame('https://github.com/example/repo.git', $r->mirrorFrom);
    }

    public function testRepoRemoveCollaborator(): void
    {
        $r = Repo::new('remove-test', '/tmp/rt')
            ->addCollaborator('alice')
            ->removeCollaborator('alice');

        $this->assertFalse($r->isCollaborator('alice'));
        $this->assertCount(0, $r->collaborators());
    }

    public function testRepoImmutability(): void
    {
        $a = Repo::new('immut', '/tmp/i');
        $b = $a->withPublic(false);
        $this->assertTrue($a->isPublic);
        $this->assertFalse($b->isPublic);
    }

    // ---- AccessControl tests ----

    public function testAccessControlCanReadPublicRepo(): void
    {
        $ac   = AccessControl::getInstance();
        $user = User::new('alice');
        $repo = Repo::new('pub', '/tmp/pub')->withPublic(true);

        $this->assertTrue($ac->canRead($user, $repo));
        $this->assertTrue($ac->canRead(null, $repo));
    }

    public function testAccessControlCanReadPrivateRepo(): void
    {
        $ac   = AccessControl::getInstance();
        $user = User::new('alice');
        $repo = Repo::new('priv', '/tmp/priv')->withPrivate(true);

        $this->assertFalse($ac->canRead(null, $repo));
        $this->assertFalse($ac->canRead($user, $repo));
    }

    public function testAccessControlAdminCanDoAnything(): void
    {
        $ac    = AccessControl::getInstance();
        $admin = User::new('admin')->withAdmin(true);
        $repo  = Repo::new('any', '/tmp/any')->withPrivate(true);

        $this->assertTrue($ac->canRead($admin, $repo));
        $this->assertTrue($ac->canWrite($admin, $repo));
        $this->assertTrue($ac->canAdmin($admin, $repo));
    }

    public function testAccessControlCollaboratorCanWritePrivateRepo(): void
    {
        $ac    = AccessControl::getInstance();
        $user  = User::new('alice');
        $repo  = Repo::new('priv', '/tmp/priv')
            ->withPrivate(true)
            ->addCollaborator('alice');

        $this->assertTrue($ac->canWrite($user, $repo));
    }

    public function testAccessControlPermissionName(): void
    {
        $this->assertSame('none',  AccessControl::permissionName(AccessControl::ACCESS_NONE));
        $this->assertSame('read',  AccessControl::permissionName(AccessControl::ACCESS_READ));
        $this->assertSame('write', AccessControl::permissionName(AccessControl::ACCESS_WRITE));
        $this->assertSame('admin', AccessControl::permissionName(AccessControl::ACCESS_ADMIN));
    }

    public function testAccessControlCanCreateRepos(): void
    {
        $ac    = AccessControl::getInstance();
        $admin = User::new('admin')->withAdmin(true);
        $user  = User::new('bob');

        $this->assertTrue($ac->canCreateRepos($admin));
        $this->assertFalse($ac->canCreateRepos($user));
        $this->assertFalse($ac->canCreateRepos(null));
    }
}
