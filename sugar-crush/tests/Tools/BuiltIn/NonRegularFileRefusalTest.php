<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Write;

/**
 * Read, Edit and Write refuse a path that exists but is not a regular file
 * (audit F-T4).
 *
 * A FIFO inside the root used to wedge all three: `filesize()` is 0 for a
 * FIFO so Read fell into `file_get_contents()`, Edit only asked
 * `file_exists()`, and Write's `is_file()` was false so it skipped the
 * "already exists" refusal and opened the FIFO for writing. Each of those
 * `open(2)` calls blocks until a peer opens the other end, which in a turn
 * means until the turn-level SIGKILL.
 *
 * WHY EVERY TOOL CALL RUNS IN A CHILD PROCESS. A regression here does not
 * fail, it hangs -- and a hung test hangs the whole suite. Holding the FIFO's
 * other end open in-process (`fopen($fifo, 'r+')`) is not enough: it unblocks
 * the `open(2)`, but then Read's `file_get_contents()` waits for EOF, which a
 * FIFO only reports once every writer is gone, and our own `r+` handle is a
 * writer. Signals are no better, since PHP restarts the interrupted syscall.
 * So each call runs in a `php -r` child with a wall-clock deadline; on overrun
 * the child is SIGKILLed and the test FAILS with "hung", which is exactly the
 * pre-fix behaviour turned into a red instead of a wedge. The child also
 * times the tool call itself, so the "returns within ~1 s" contract is
 * asserted on the call, not on the interpreter's start-up cost.
 *
 * @see Read
 * @see Edit
 * @see Write
 */
final class NonRegularFileRefusalTest extends TestCase
{
    /** Generous: covers interpreter start-up + autoload on a loaded CI box. */
    private const HANG_GUARD_SECONDS = 10.0;

    /** The refusal itself is a stat(), so this bound is not about speed. */
    private const CALL_BOUND_SECONDS = 1.0;

    private string $root;

    protected function setUp(): void
    {
        // A failed assertion, not a skip: ext-posix is present wherever this
        // suite runs, and an off-roster skip reddens SuiteSkipRoster anyway.
        self::assertTrue(\function_exists('posix_mkfifo'), 'ext-posix is required to build the FIFO fixture');

        $this->root = (string) realpath((string) sys_get_temp_dir()) . '/sugarcrush_nonregular_' . uniqid((string) getmypid(), true);
        mkdir($this->root, 0o777, true);
        self::assertTrue(posix_mkfifo($this->root . '/p', 0o600), 'could not create the FIFO fixture');
    }

    protected function tearDown(): void
    {
        foreach (['p', 'regular.txt', 'link.txt'] as $name) {
            $path = $this->root . '/' . $name;
            if (is_link($path) || file_exists($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testReadRefusesAFifoInsteadOfBlocking(): void
    {
        $this->assertRefusedAsNonRegular(Read::class, $this->root, [
            'file_path' => 'p',
            'description' => 'probe a fifo',
        ]);
    }

    public function testEditRefusesAFifoInsteadOfBlocking(): void
    {
        $this->assertRefusedAsNonRegular(Edit::class, $this->root, [
            'file_path' => 'p',
            'old_string' => 'a',
            'new_string' => 'b',
        ]);
    }

    public function testWriteRefusesAFifoWithoutOverwrite(): void
    {
        $this->assertRefusedAsNonRegular(Write::class, $this->root, [
            'file_path' => 'p',
            'content' => 'x',
        ]);
    }

    public function testWriteRefusesAFifoEvenWithOverwrite(): void
    {
        $this->assertRefusedAsNonRegular(Write::class, $this->root, [
            'file_path' => 'p',
            'content' => 'x',
            'overwrite' => true,
        ]);
    }

    /**
     * A character device does not block on read, but it is no more a source
     * file than a FIFO is; only reachable with no root jail.
     */
    public function testUnjailedReadRefusesACharacterDevice(): void
    {
        self::assertFileExists('/dev/null');

        $this->assertRefusedAsNonRegular(Read::class, null, [
            'file_path' => '/dev/null',
            'description' => 'probe a device',
        ]);
    }

    /**
     * The guard is is_file(), which follows symlinks: a link to a regular
     * file must keep working, so the refusal cannot have been written as
     * "not a plain inode".
     */
    public function testReadStillFollowsASymlinkToARegularFile(): void
    {
        file_put_contents($this->root . '/regular.txt', "hello\n");
        symlink($this->root . '/regular.txt', $this->root . '/link.txt');

        $result = (new Read($this->root))->execute([
            'file_path' => 'link.txt',
            'description' => 'read through a symlink',
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('hello', $result->content());
    }

    /**
     * @param class-string    $toolClass
     * @param array<string,mixed> $args
     */
    private function assertRefusedAsNonRegular(string $toolClass, ?string $root, array $args): void
    {
        $outcome = $this->runToolInChild($toolClass, $root, $args);

        self::assertTrue($outcome['isError'], $toolClass . ' did not report an error: ' . $outcome['content']);
        self::assertStringContainsString('not a regular file', $outcome['content']);
        self::assertLessThan(self::CALL_BOUND_SECONDS, $outcome['seconds'], $toolClass . ' took too long to refuse');
    }

    /**
     * @param class-string        $toolClass
     * @param array<string,mixed> $args
     * @return array{isError: bool, content: string, seconds: float}
     */
    private function runToolInChild(string $toolClass, ?string $root, array $args): array
    {
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = <<<'PHP'
            [$autoload, $class, $root, $args] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
            require $autoload;
            $tool = new $class($root);
            $t = hrtime(true);
            $r = $tool->execute($args);
            $seconds = (hrtime(true) - $t) / 1e9;
            echo json_encode(['isError' => $r->isError(), 'content' => $r->content(), 'seconds' => $seconds]);
            PHP;
        $payload = json_encode([$autoload, $toolClass, $root, $args], JSON_THROW_ON_ERROR);

        $proc = proc_open(
            [\PHP_BINARY, '-r', $code, $payload],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'could not spawn the child');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::HANG_GUARD_SECONDS;
        $hung = false;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $hung = true;
                break;
            }
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, 100_000);
        }
        if ($hung) {
            proc_terminate($proc, 9);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        self::assertFalse($hung, $toolClass . ' hung on a non-regular file (child SIGKILLed after '
            . self::HANG_GUARD_SECONDS . 's)');

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, 'child produced no result; stdout=' . $stdout . ' stderr=' . $stderr);

        return [
            'isError' => (bool) $decoded['isError'],
            'content' => (string) $decoded['content'],
            'seconds' => (float) $decoded['seconds'],
        ];
    }
}
