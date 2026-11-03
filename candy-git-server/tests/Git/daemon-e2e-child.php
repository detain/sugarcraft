<?php

/**
 * Daemon e2e child harness for GitDaemonWireConformanceTest.
 *
 * The parent test passes: argv[1] = scratch dir, argv[2] = 1 when a real
 * seeded bare repo ('seed.git') should be created and registered, 0 for a
 * loose-ref 'repo.git' fixture. The scratch dir already carries a
 * config.yaml binding git.listen_addr to a test-probed free port.
 *
 * Handshake: the child rewrites listen_addr with the REAL bound port after
 * the private socket creation (port 0 = ephemeral), then prints
 * "PORT <n>" on stdout. serve() then blocks in the fixed mainLoop until the
 * parent kills it in teardown.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\GitDaemon;
use SugarCraft\GitServer\Repo;

// Self-leash: an abandoned harness must die of old age even if the
// parent test is killed before its teardown (a leaked serve() loop
// would hold the port and confuse later runs).
pcntl_async_signals(true);
pcntl_signal(SIGALRM, static fn () => exit(0));
pcntl_alarm(120);

$dir = (string) $argv[1];
$seed = ($argv[2] ?? '0') === '1';

$config = Config::load($dir . '/config.yaml');
$daemon = new GitDaemon($config);

$create = new ReflectionMethod(GitDaemon::class, 'createServerSocket');
$create->setAccessible(true);
$socket = $create->invoke($daemon);
if ($socket === false) {
 fwrite(STDERR, "child: bind failed\n");
 exit(2);
}

$host = '';
$port = 0;
if (!socket_getsockname($socket, $host, $port)) {
 fwrite(STDERR, "child: getsockname failed\n");
 exit(3);
}
$mc = (int) ($argv[3] ?? 4);

// Hand the loop the port the kernel just assigned: SO_REUSEADDR (set in
// createServerSocket) makes the close-and-rebind window benign, and the
// parent learns the REAL ephemeral port through stdout.
socket_close($socket);
\file_put_contents(
 $dir . '/config.yaml',
 "name: \"E2E\"\ngit: { listen_addr: \"127.0.0.1:{$port}\", idle_timeout: 8, max_connections: {$mc}, allow_anonymous_read: true }\n"
);
// Re-read so serve()/accept enforce the same numbers the parent tests against.
$config = Config::load($dir . '/config.yaml');
$daemon = new GitDaemon($config);

if ($seed) {
 $work = $dir . '/work';
 mkdir($work, 0755, true);
 exec('git -C ' . escapeshellarg($work) . ' init -q 2>/dev/null');
 file_put_contents($work . '/hello.txt', "hello daemon\n");
 exec('git -C ' . escapeshellarg($work) . ' add hello.txt 2>/dev/null');
 exec('git -C ' . escapeshellarg($work) . ' -c user.email=t@t -c user.name=t commit -q -m seed 2>/dev/null');
 $path = $dir . '/seed.git';
 exec('git init --bare -q ' . escapeshellarg($path) . ' 2>/dev/null');
 exec('git -C ' . escapeshellarg($work) . ' push -q ' . escapeshellarg($path) . ' master 2>/dev/null');
 $daemon->registerRepo(Repo::new('seed.git', $path)->withVisibility(\SugarCraft\GitServer\Visibility::Public));
} else {
 $path = $dir . '/repo.git';
 exec('git init --bare -q ' . escapeshellarg($path) . ' 2>/dev/null');
 // The delegated `git upload-pack` child refuses to advertise a ref
 // whose object does not exist, so the loose fake (40 x 'a' without
 // objects) is replaced by a REAL commit of the well-known empty
 // tree — and the harness fails LOUD (never silently empty) if the
 // fixture cannot be built.
 $commitOut = [];
 $fixtureRc = 0;
 exec('git -C ' . escapeshellarg($path) . ' -c user.email=t@t -c user.name=t commit-tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904 -m empty 2>/dev/null', $commitOut, $fixtureRc);
 $commit = trim($commitOut[0] ?? '');
 if ($fixtureRc !== 0 || !preg_match('/^[0-9a-f]{40}$/', $commit)) {
 fwrite(STDERR, "child: fixture commit-tree failed rc={$fixtureRc} out=" . ($commitOut[0] ?? '') . "\n");
 exit(5);
 }
 file_put_contents($path . '/HEAD', "ref: refs/heads/main\n");
 file_put_contents($path . '/refs/heads/main', $commit . "\n");
 $daemon->registerRepo(Repo::new('repo.git', $path)->withVisibility(\SugarCraft\GitServer\Visibility::Public));
}

fwrite(STDOUT, "PORT {$port}\n");
fflush(STDOUT);

exit($daemon->serve());
