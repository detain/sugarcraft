<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Write;

/**
 * Edit and Write publish their result atomically and keep the file's identity
 * (audit F-T7).
 *
 * Both tools used to `file_put_contents()` the target, which truncates before
 * it writes: the turn fork is SIGKILLed on Esc-Esc and at the completion
 * deadline, and an ENOSPC can land part-way, so either left a 0-byte or torn
 * source file with no undo. The kill tests below reproduce that for real — a
 * child under `ulimit -f` is killed by SIGXFSZ part-way through the write,
 * which is the same mid-write death without needing a timed signal. MEASURED
 * before the fix: a 262,149-byte file came back as 65,536 bytes.
 *
 * The rest pin what a rename must not lose: the permission bits, the group
 * where the writer may set it, a symlink, the other names of a hard link, and
 * the in-place behaviour a read-only directory or file had before.
 */
final class AtomicEditWriteTest extends TestCase
{
    /** Bound on a child that should die in milliseconds; only a hang reaches it. */
    private const CHILD_GUARD_SECONDS = 20.0;

    private string $dir;

    private int $umask;

    protected function setUp(): void
    {
        $this->umask = umask();
        $this->dir = (string) realpath((string) sys_get_temp_dir()) . '/sugarcrush_atomic_' . uniqid((string) getmypid(), true);
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        self::removeTree($this->dir);
        @unlink($this->dir . '.payload.json');
    }

    public function testEditKilledMidWriteLeavesTheOriginalBytesIntact(): void
    {
        $original = "MARK\n" . str_repeat('x', 256 * 1024);
        file_put_contents($this->dir . '/big.txt', $original);

        $status = $this->runKilledMidWrite(Edit::class, [
            'file_path' => 'big.txt',
            'old_string' => 'MARK',
            'new_string' => 'MORK',
        ]);

        self::assertNotSame(0, $status, 'the child must have been killed by the file-size limit, or this proves nothing');
        clearstatcache();
        self::assertSame($original, file_get_contents($this->dir . '/big.txt'), 'a write killed part-way must leave the original bytes, not a truncated file');
    }

    public function testWriteOverwriteKilledMidWriteLeavesTheOriginalBytesIntact(): void
    {
        $original = str_repeat('o', 200 * 1024);
        file_put_contents($this->dir . '/big.txt', $original);

        $status = $this->runKilledMidWrite(Write::class, [
            'file_path' => 'big.txt',
            'content' => str_repeat('n', 256 * 1024),
            'overwrite' => true,
        ]);

        self::assertNotSame(0, $status, 'the child must have been killed by the file-size limit, or this proves nothing');
        clearstatcache();
        self::assertSame($original, file_get_contents($this->dir . '/big.txt'));
    }

    public function testEditWhoseWriteFailsHalfwayKeepsTheOriginalAndLeavesNoTemp(): void
    {
        $file = $this->dir . '/a.php';
        file_put_contents($file, "<?php\necho 'old';\n");

        $result = (new Edit(writeSeam: self::halfThenThrow()))->execute([
            'file_path' => $file,
            'old_string' => "'old'",
            'new_string' => "'new'",
        ]);

        self::assertTrue($result->isError());
        self::assertSame("Error writing file: $file", $result->content());
        self::assertSame("<?php\necho 'old';\n", file_get_contents($file));
        self::assertSame(['a.php'], self::entries($this->dir), 'the failed temp must be unlinked');
    }

    public function testWriteOverwriteWhoseWriteFailsHalfwayKeepsTheOriginalAndLeavesNoTemp(): void
    {
        $file = $this->dir . '/a.txt';
        file_put_contents($file, 'keep me');

        $result = (new Write(writeSeam: self::halfThenThrow()))->execute([
            'file_path' => $file,
            'content' => 'replacement text',
            'overwrite' => true,
        ]);

        self::assertTrue($result->isError());
        self::assertSame("Error writing file: $file", $result->content());
        self::assertSame('keep me', file_get_contents($file));
        self::assertSame(['a.txt'], self::entries($this->dir));
    }

    public function testWriteCreateWhoseWriteFailsHalfwayLeavesNoFile(): void
    {
        $file = $this->dir . '/new.txt';

        $result = (new Write(writeSeam: self::halfThenThrow()))->execute([
            'file_path' => $file,
            'content' => 'never half of this',
        ]);

        self::assertTrue($result->isError());
        self::assertSame([], self::entries($this->dir), 'neither a half-written new file nor a temp may be left');
    }

    /** @return array<string, array{int}> */
    public static function modes(): array
    {
        return ['0640' => [0o640], '0755' => [0o755], '0600' => [0o600]];
    }

    #[DataProvider('modes')]
    public function testEditPreservesTheFileMode(int $mode): void
    {
        $file = $this->dir . '/m.sh';
        file_put_contents($file, "echo one\n");
        chmod($file, $mode);
        $inode = fileinode($file);

        $result = (new Edit())->execute(['file_path' => $file, 'old_string' => 'one', 'new_string' => 'two']);

        self::assertFalse($result->isError(), $result->content());
        clearstatcache();
        self::assertSame("echo two\n", file_get_contents($file));
        self::assertSame($mode, fileperms($file) & 0o7777);
        self::assertNotSame($inode, fileinode($file), 'a plain file is published as a new inode by rename, not rewritten in place');
    }

    #[DataProvider('modes')]
    public function testWriteOverwritePreservesTheFileMode(int $mode): void
    {
        $file = $this->dir . '/m.sh';
        file_put_contents($file, "echo one\n");
        chmod($file, $mode);

        $result = (new Write())->execute(['file_path' => $file, 'content' => "echo two\n", 'overwrite' => true]);

        self::assertFalse($result->isError(), $result->content());
        clearstatcache();
        self::assertSame("echo two\n", file_get_contents($file));
        self::assertSame($mode, fileperms($file) & 0o7777);
    }

    /** @return array<string, array{int, int}> */
    public static function umasks(): array
    {
        return ['022' => [0o022, 0o644], '027' => [0o027, 0o640], '077' => [0o077, 0o600]];
    }

    /**
     * file_put_contents() gave a new file 0666 & ~umask; a tempnam() temp is
     * 0600 regardless, so this pins that the atomic create did not swap one
     * for the other.
     */
    #[DataProvider('umasks')]
    public function testWriteCreatesANewFileAtTheUmaskDefault(int $umask, int $expected): void
    {
        umask($umask);
        $file = $this->dir . '/sub/new.txt';

        $result = (new Write())->execute(['file_path' => $file, 'content' => 'hello']);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('hello', file_get_contents($file));
        self::assertSame($expected, fileperms($file) & 0o7777);
        self::assertSame(['new.txt'], self::entries($this->dir . '/sub'));
    }

    public function testEditThroughASymlinkUpdatesTheTargetAndKeepsTheLink(): void
    {
        mkdir($this->dir . '/real');
        $target = $this->dir . '/real/t.txt';
        file_put_contents($target, 'alpha');
        chmod($target, 0o640);
        $link = $this->dir . '/link.txt';
        symlink($target, $link);

        $result = (new Edit())->execute(['file_path' => $link, 'old_string' => 'alpha', 'new_string' => 'beta']);

        self::assertFalse($result->isError(), $result->content());
        clearstatcache();
        self::assertTrue(is_link($link), 'rename over the link would have replaced it with a regular file');
        self::assertSame($target, readlink($link));
        self::assertSame('beta', file_get_contents($target));
        self::assertSame(0o640, fileperms($target) & 0o7777);
        self::assertSame(['t.txt'], self::entries($this->dir . '/real'), 'the temp lands beside the target and is renamed away');
    }

    public function testWriteOverwriteThroughASymlinkUpdatesTheTargetAndKeepsTheLink(): void
    {
        $target = $this->dir . '/t.txt';
        file_put_contents($target, 'alpha');
        $link = $this->dir . '/link.txt';
        symlink('t.txt', $link);

        $result = (new Write())->execute(['file_path' => $link, 'content' => 'gamma', 'overwrite' => true]);

        self::assertFalse($result->isError(), $result->content());
        clearstatcache();
        self::assertTrue(is_link($link));
        self::assertSame('t.txt', readlink($link), 'a relative link keeps its own spelling');
        self::assertSame('gamma', file_get_contents($target));
    }

    public function testEditOfAHardLinkUpdatesEveryName(): void
    {
        $a = $this->dir . '/a.txt';
        $b = $this->dir . '/b.txt';
        file_put_contents($a, 'one two three');
        link($a, $b);

        $result = (new Edit())->execute(['file_path' => $a, 'old_string' => 'two', 'new_string' => '2']);

        self::assertFalse($result->isError(), $result->content());
        clearstatcache();
        self::assertSame('one 2 three', file_get_contents($a));
        self::assertSame('one 2 three', file_get_contents($b), 'a rename would have split the link and left the other name stale');
        self::assertSame(fileinode($a), fileinode($b));
        self::assertSame(2, stat($a)['nlink']);
    }

    public function testWriteOverwriteOfAHardLinkUpdatesEveryName(): void
    {
        $a = $this->dir . '/a.txt';
        $b = $this->dir . '/b.txt';
        file_put_contents($a, 'a much longer original body');
        link($a, $b);

        $result = (new Write())->execute(['file_path' => $b, 'content' => 'short', 'overwrite' => true]);

        self::assertFalse($result->isError(), $result->content());
        clearstatcache();
        self::assertSame('short', file_get_contents($a), 'the in-place write must still cut the old tail off');
        self::assertSame('short', file_get_contents($b));
        self::assertSame(fileinode($a), fileinode($b));
    }

    /**
     * The in-place write worked in a directory the user may not write, so
     * the atomic one must fall back rather than turn it into an error. Root
     * ignores the directory bit, so there the rename simply succeeds and only
     * the outcome is asserted; for everyone else the unchanged inode proves
     * the fallback ran.
     */
    public function testEditInAReadOnlyDirectoryStillEditsAWritableFile(): void
    {
        $locked = $this->dir . '/locked';
        mkdir($locked);
        $file = $locked . '/f.txt';
        file_put_contents($file, 'before');
        chmod($file, 0o664);
        $inode = fileinode($file);
        chmod($locked, 0o555);

        $result = (new Edit())->execute(['file_path' => $file, 'old_string' => 'before', 'new_string' => 'after']);

        chmod($locked, 0o755);
        self::assertFalse($result->isError(), $result->content());
        clearstatcache();
        self::assertSame('after', file_get_contents($file));
        self::assertSame(0o664, fileperms($file) & 0o7777);
        self::assertSame(['f.txt'], self::entries($locked));
        if (posix_geteuid() !== 0) {
            self::assertSame($inode, fileinode($file), 'a read-only directory must take the in-place fallback');
        }
    }

    /**
     * rename() needs only the directory's write bit, so without an explicit
     * check a 0444 file — which the in-place write always failed on — would be
     * silently replaced. Root may write it either way.
     */
    public function testEditOfAReadOnlyFileIsStillRefused(): void
    {
        $file = $this->dir . '/ro.txt';
        file_put_contents($file, 'frozen');
        chmod($file, 0o444);

        $result = (new Edit())->execute(['file_path' => $file, 'old_string' => 'frozen', 'new_string' => 'thawed']);

        clearstatcache();
        if (posix_geteuid() === 0) {
            self::assertFalse($result->isError(), $result->content());
            self::assertSame('thawed', file_get_contents($file));
        } else {
            self::assertTrue($result->isError());
            self::assertSame("Error writing file: $file", $result->content());
            self::assertSame('frozen', file_get_contents($file));
            self::assertSame(['ro.txt'], self::entries($this->dir));
        }
        self::assertSame(0o444, fileperms($file) & 0o7777);
    }

    /**
     * Ownership is best effort: root restores the owner, and any user restores
     * a group it belongs to. A non-root user with no supplementary group can
     * only show the group is still its own, which is the same claim at its
     * weakest.
     */
    public function testEditKeepsTheGroupAndOwnerWhereTheWriterMaySetThem(): void
    {
        $file = $this->dir . '/g.txt';
        file_put_contents($file, 'grouped');
        $wantUid = posix_geteuid();
        $wantGid = (int) filegroup($file);
        if ($wantUid === 0) {
            $wantUid = 65534;
            $wantGid = 65534;
            chown($file, $wantUid);
            chgrp($file, $wantGid);
        } else {
            foreach (posix_getgroups() ?: [] as $gid) {
                if ($gid !== $wantGid && @chgrp($file, $gid)) {
                    $wantGid = $gid;
                    break;
                }
            }
        }
        chmod($file, 0o660);
        clearstatcache();

        $result = (new Edit())->execute(['file_path' => $file, 'old_string' => 'grouped', 'new_string' => 'regrouped']);

        self::assertFalse($result->isError(), $result->content());
        clearstatcache();
        self::assertSame('regrouped', file_get_contents($file));
        self::assertSame($wantGid, filegroup($file));
        self::assertSame($wantUid, fileowner($file));
        self::assertSame(0o660, fileperms($file) & 0o7777, 'chmod after chown, or the chown would have cleared bits');
    }

    /**
     * Runs one tool call in a child whose file-size limit (64 KiB) sits below
     * the payload, so the kernel kills it with SIGXFSZ part-way through the
     * write — the same mid-write death a SIGKILLed turn fork suffers.
     *
     * @param class-string $toolClass
     * @param array<string, mixed> $args
     */
    private function runKilledMidWrite(string $toolClass, array $args): int
    {
        $autoload = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $code = <<<'PHP'
            [$autoload, $class, $root, $args] = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
            require $autoload;
            (new $class(root: $root))->execute($args);
            PHP;
        // Through a file, not argv: a 256 KiB payload is past the kernel's
        // per-argument limit (E2BIG). Beside the fixture dir, not in it, so the
        // directory the tool writes into holds only what the tool put there.
        $payload = $this->dir . '.payload.json';
        file_put_contents($payload, json_encode([$autoload, $toolClass, $this->dir, $args], JSON_THROW_ON_ERROR));
        // ulimit -c 0: the SIGXFSZ default action dumps core, and the core
        // must not land in the fixture directory or anywhere else.
        $shell = 'ulimit -c 0; ulimit -f 64; exec ' . escapeshellarg(\PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($payload);

        $proc = proc_open(
            ['bash', '-c', $shell],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $this->dir,
        );
        self::assertIsResource($proc);

        $deadline = microtime(true) + self::CHILD_GUARD_SECONDS;
        do {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($proc, 9);
            proc_close($proc);
            self::fail('the size-limited child hung instead of dying mid-write');
        }
        proc_close($proc);

        return $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
    }

    /** @return \Closure(resource, string): void */
    private static function halfThenThrow(): \Closure
    {
        return static function ($handle, string $contents): void {
            fwrite($handle, substr($contents, 0, intdiv(strlen($contents), 2)));
            throw new \RuntimeException('injected failure half-way through the payload');
        };
    }

    /** @return list<string> sorted entries, dot-files (temps) included */
    private static function entries(string $dir): array
    {
        $names = array_values(array_diff((array) scandir($dir), ['.', '..']));
        sort($names);

        return $names;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0o755);
        foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
            self::removeTree($path . '/' . $name);
        }
        @rmdir($path);
    }
}
