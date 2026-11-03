<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\GitDaemon;
use SugarCraft\GitServer\Repo;

/**
 * Pins for repository containment and the fail-closed daemon
 * posture :
 *
 * - '.' and '..' pass the old /^[a-zA-Z0-9._-]+$/ charset gate and, glued
 * onto the repos root, address (or init) directories OUTSIDE the jail —
 * reachable anonymously over the daemon and on-demand-creating over the
 * SSH path. Repo::isJailSafeName rejects them at every choke point.
 * - the git daemon protocol has no authentication, so the daemon must never
 * serve git-receive-pack: "one config flag from default = unauthenticated
 * writes" was exactly the pre-fix posture.
 *
 * The daemon dispatch seam is driven directly (private method via
 * reflection) with the client entry shaped exactly as acceptConnection()
 * builds it; the socket is a file:// handle so the written packet survives
 * closeClient()'s fclose and can be asserted.
 */
final class RepoJailContainmentTest extends TestCase
{
 private string $tmpDir;

 protected function setUp(): void
 {
 parent::setUp();
 $this->tmpDir = \sys_get_temp_dir() . '/git-jail-test-' . \uniqid();
 \mkdir($this->tmpDir, 0700, true);
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

 // -------------------------------------------------------------------------
 // Name validator table
 // -------------------------------------------------------------------------

 public function testJailSafeNameTable(): void
 {
 foreach (['foo', 'foo.git', 'a-b_c.1', 'a..b'] as $ok) {
 $this->assertTrue(Repo::isJailSafeName($ok), "$ok must be accepted");
 }
 foreach (['', '.', '..', '...', './x', '../x', 'a/b', 'a b', "a\nb", '-x'] as $bad) {
 $this->assertFalse(Repo::isJailSafeName($bad), \var_export($bad, true) . ' must be refused');
 }
 }

 // -------------------------------------------------------------------------
 // Daemon dispatch gate
 // -------------------------------------------------------------------------

 /**
 * @return array{0: string, 1: array<int>} packet bytes written + remaining clients
 */
 private function driveDispatch(GitDaemon $daemon, string $buffer): array
 {
 $outFile = $this->tmpDir . '/wire-' . \uniqid();
 $socket = \fopen('file://' . $outFile, 'wb');
 $this->assertIsResource($socket);

 $clients = new \ReflectionProperty(GitDaemon::class, 'clients');
 $clients->setAccessible(true);
 $clients->setValue($daemon, [
 0 => [
 'socket' => $socket,
 'connected_at' => \time(),
 'last_activity' => \time(),
 'buffer' => $buffer,
 'handled' => false,
 ],
 ]);

 $dispatch = new \ReflectionMethod($daemon, 'dispatchClientRequest');
 $dispatch->setAccessible(true);
 $dispatch->invoke($daemon, 0);

 /** @var array<int, array<string, mixed>> $after */
 $after = $clients->getValue($daemon);
 \clearstatcache();

 return [(string) \file_get_contents($outFile), \array_keys($after)];
 }

 public function testDaemonRefusesReceivePackWithNoSideEffects(): void
 {
 $daemon = new GitDaemon(Config::fromDefaults());

 [$bytes, $remaining] = $this->driveDispatch(
 $daemon,
 "git-receive-pack /dest\n" . \str_repeat('0', 40) . ' ' . \str_repeat('a', 40) . " refs/heads/master\n"
 );

 $this->assertStringContainsString('git-receive-pack is not served over the anonymous daemon', $bytes);
 $this->assertSame([], $remaining, 'refused client must be closed');
 }

 public function testDaemonRefusesTraversalRepoNameBeforeAnyLookup(): void
 {
 $daemon = new GitDaemon(Config::fromDefaults());

 [$bytes, $remaining] = $this->driveDispatch($daemon, "git-upload-pack /..\n");

 $this->assertStringContainsString('Invalid repository name', $bytes);
 $this->assertSame([], $remaining);
 }

 public function testDaemonRefusesDotRepoName(): void
 {
 $daemon = new GitDaemon(Config::fromDefaults());

 [$bytes, $remaining] = $this->driveDispatch($daemon, "git-upload-pack /.\n");

 $this->assertStringContainsString('Invalid repository name', $bytes);
 $this->assertSame([], $remaining);
 }

}
