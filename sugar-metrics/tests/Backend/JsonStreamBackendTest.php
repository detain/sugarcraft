<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\JsonStreamBackend;
use SugarCraft\Metrics\Tests\Support\ShortWriteStream;
use PHPUnit\Framework\TestCase;

final class JsonStreamBackendTest extends TestCase
{
    public function testEmitsOneJsonLinePerEvent(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->counter('hits', 1, ['route' => '/x']);
        $b->gauge('q', 4);
        $b->histogram('lat', 0.005);
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        $lines = array_filter(explode("\n", $contents));
        $this->assertCount(3, $lines);
        $a = json_decode((string) $lines[0], true);
        $this->assertSame('counter', $a['kind']);
        $this->assertSame('hits',    $a['name']);
        $this->assertSame(1,         $a['value']);
        $this->assertSame(['route' => '/x'], (array) $a['tags']);
        $b2 = json_decode((string) $lines[1], true);
        $this->assertSame('gauge', $b2['kind']);
        $c = json_decode((string) $lines[2], true);
        $this->assertSame('histogram', $c['kind']);
        fclose($stream);
    }

    public function testRejectsInvalidTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JsonStreamBackend(42);
    }

    public function testWriteFailureThrows(): void
    {
        // A read-only stream rejects all writes.
        $stream = fopen('php://memory', 'r');
        $b = new JsonStreamBackend($stream);
        $this->expectException(\RuntimeException::class);
        $b->counter('hits', 1);
        fclose($stream);
    }

    public function testConstructWithNullDefaultsToStderr(): void
    {
        // sugar-metrics-019: the old arm constructed the default-target
        // backend and really emitted into the suite's own stderr asserting
        // only "did not throw" — polluting CI logs while leaving the actual
        // property (null resolves to php://stderr and lands exactly one JSON
        // line there) unpinned. The contract is now proven in a child
        // process: its stderr is captured on a pipe, the record is read
        // back, and the suite's own streams stay clean. A memory-stream
        // substitute was rejected: it would not pin the php://stderr
        // resolution itself.
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $this->assertFileExists($autoload);
        $cmd = [
            PHP_BINARY, '-r',
            'require $argv[1];'
            . ' $b = SugarCraft\\Metrics\\Backend\\JsonStreamBackend::new(null);'
            . ' $b->counter($argv[2], 1, ["probe" => "child"]);'
            . ' $b->flush();',
            $autoload,
            'stderr.probe',
        ];
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        $this->assertSame('', $stdout, 'the record must ride stderr, never stdout');
        $this->assertSame("\n", substr($stderr, -1));
        $lines = explode("\n", rtrim($stderr, "\n"));
        $this->assertCount(1, $lines, 'exactly one record line on php://stderr');
        $record = json_decode($lines[0], true);
        $this->assertIsArray($record, 'the stderr line must be decodable JSON');
        $this->assertSame('stderr.probe', $record['name']);
        $this->assertSame('counter', $record['kind']);
        $this->assertSame(1, $record['value']);
        $this->assertSame(['probe' => 'child'], $record['tags']);
    }

    public function testConstructWithFilePath(): void
    {
        $path = sys_get_temp_dir() . '/jsonstream-test-' . uniqid() . '.json';
        try {
            $b = new JsonStreamBackend($path);
            $b->counter('hits', 1);
            $b->flush();

            $content = (string) file_get_contents($path);
            $lines = array_filter(explode("\n", $content));
            $this->assertCount(1, $lines);
            $decoded = json_decode((string) $lines[0], true);
            $this->assertSame('counter', $decoded['kind']);
            $this->assertSame('hits', $decoded['name']);
            $this->assertSame(1, $decoded['value']);
        } finally {
            @unlink($path);
        }
    }

    public function testConstructWithFilePathThatCannotBeOpened(): void
    {
        // Both uncreatable shapes — parent dir missing AND parent is a
        // regular file (ENOTDIR, hermetic: it fails for every uid including
        // root) — must refuse through the RuntimeException contract without
        // raising a PHP diagnostic: the constructor probes the door first.
        $dir = sys_get_temp_dir() . '/sugar-metrics-jsonstream-door-' . uniqid();
        mkdir($dir);
        $blocker = $dir . '/blocker';
        file_put_contents($blocker, '');
        $missingParent = $dir . '/nested/metrics.json';
        $fileParent = $blocker . '/metrics.json';

        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true; // consume the diagnostic: the refusal must stay silent
        });

        $refusalMessages = [];
        try {
            foreach ([$missingParent, $fileParent] as $path) {
                try {
                    new JsonStreamBackend($path);
                } catch (\RuntimeException $e) {
                    $refusalMessages[$path] = $e->getMessage();
                }
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([$missingParent, $fileParent], array_keys($refusalMessages), 'both uncreatable shapes must refuse');
        foreach ($refusalMessages as $path => $message) {
            $this->assertStringContainsString($path, $message, "refusal for {$path} must name the target");
        }
        $this->assertSame([], $raised, 'the refusal door must not raise a PHP diagnostic');

        @unlink($blocker);
        @rmdir($dir);
    }

    public function testAsyncCounterEmitsCorrectKind(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->asyncCounter('jvm_gc', 2.0, ['gen' => 'old']);
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        $lines = array_filter(explode("\n", $contents));
        $this->assertCount(1, $lines);
        $decoded = json_decode((string) $lines[0], true);
        $this->assertSame('async_counter', $decoded['kind']);
        $this->assertSame('jvm_gc', $decoded['name']);
        $this->assertEqualsWithDelta(2.0, $decoded['value'], 0.001);
        $this->assertSame(['gen' => 'old'], (array) $decoded['tags']);
        fclose($stream);
    }

    public function testAsyncGaugeEmitsCorrectKind(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->asyncGauge('heap_used', 1.5, ['area' => 'old']);
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        $lines = array_filter(explode("\n", $contents));
        $this->assertCount(1, $lines);
        $decoded = json_decode((string) $lines[0], true);
        $this->assertSame('async_gauge', $decoded['kind']);
        $this->assertSame('heap_used', $decoded['name']);
        $this->assertSame(1.5, $decoded['value']);
        fclose($stream);
    }

    public function testUpDownCounterEmitsCorrectKind(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->upDownCounter('conns', 3.0);
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        $lines = array_filter(explode("\n", $contents));
        $decoded = json_decode((string) $lines[0], true);
        $this->assertSame('up_down_counter', $decoded['kind']);
        $this->assertSame('conns', $decoded['name']);
        $this->assertEqualsWithDelta(3.0, $decoded['value'], 0.001);
        fclose($stream);
    }

    public function testDescribeIsNoOp(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->describe(new \SugarCraft\Metrics\Descriptor('test', 'help', 'counter'));
        // No output expected — describe is a no-op for JSON stream.
        rewind($stream);
        $this->assertSame('', (string) stream_get_contents($stream));
        fclose($stream);
    }

    public function testFlushOnValidStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->counter('x', 1);
        $b->flush(); // Must not throw.
        $this->assertTrue(true);
        fclose($stream);
    }

    public function testRemoveIsNoOp(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->remove('any', ['a' => 'b']); // Must not throw.
        $this->assertTrue(true);
        fclose($stream);
    }

    public function testClearIsNoOp(): void
    {
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->counter('x', 1);
        $b->clear(); // Must not throw.
        $this->assertTrue(true);
        fclose($stream);
    }

    public function testThrowOnErrorFalseSilentlyDropsTheShortWrittenRecord(): void
    {
        // The old arm wrote a FULL record to php://memory and congratulated
        // itself on not throwing — the silent-drop branch never ran. Now the
        // fault seam really truncates the write: silent mode must neither
        // throw nor poison the successor line.
        ShortWriteStream::register();
        ShortWriteStream::arm(120);
        $stream = fopen('metrics-shortwrite://x', 'w');
        $b = new JsonStreamBackend($stream, throwOnError: false);
        $b->counter('torn', 1.0, ['route' => '/a/very/long/path/making/the/record/comfortably/exceed/the/fault']);
        $b->counter('second', 2.0);
        fclose($stream);
        $lines = explode("\n", ShortWriteStream::$buf);
        $this->assertCount(3, $lines, 'torn record, quarantined successor, trailing empty');
        $this->assertNull(json_decode($lines[0], true), 'the first 120 bytes are a torn record by construction');
        $decoded = json_decode((string) $lines[1], true);
        $this->assertIsArray($decoded, 'the successor must stand on its own line after quarantine');
        $this->assertSame('second', $decoded['name']);
    }

    public function testDestructorClosesOwnedStream(): void
    {
        $path = sys_get_temp_dir() . '/jsonstream-dtor-' . uniqid() . '.json';
        try {
            $b = new JsonStreamBackend($path);
            $b->counter('x', 1);
            unset($b); // Triggers destructor — must not throw.
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function testJsonEncodeFailureIsHandledGracefully(): void
    {
        // Invalid UTF-8 makes json_encode() return false — the old arm never
        // reached that branch (it wrote a healthy record instead). Silent
        // mode now really runs the encode-failure drop: nothing reaches the
        // stream and nothing throws.
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream, throwOnError: false);
        $b->counter("host\xFFle", 1.0);
        rewind($stream);
        $this->assertSame('', (string) stream_get_contents($stream), 'a record that cannot encode must not be written');
        fclose($stream);

        // Loud mode refuses the same record instead of dropping it silently.
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream, throwOnError: true);
        $message = null;
        try {
            $b->counter("host\xFFle", 1.0);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }
        rewind($stream);
        $this->assertNotNull($message, 'loud mode must report the unencodable record');
        $this->assertSame('', (string) stream_get_contents($stream), 'the refusal happens before any write');
        fclose($stream);
    }

    public function testShortWriteQuarantinesTheTornRecordSoSuccessorsStandAlone(): void
    {
        // sugar-metrics-009(a): the pre-fix branch only throw/returned, so
        // the half-written line stayed un-terminated and EVERY later record
        // spliced onto the tear — one failure corrupted the file from that
        // offset on, in a stream whose contract is "every event gets a fresh
        // line". The fault seam truncates the write mid-record (120 bytes
        // accepted, retry hard-blocked, then capacity returns); loud mode
        // must refuse the record AND quarantine the tear with a newline.
        ShortWriteStream::register();
        ShortWriteStream::arm(120);
        $stream = fopen('metrics-shortwrite://x', 'w');
        $b = new JsonStreamBackend($stream);
        $message = null;
        try {
            $b->counter('torn', 1.0, ['route' => '/a/very/long/path/making/the/record/comfortably/exceed/the/fault']);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }
        $this->assertNotNull($message, 'short write must refuse in loud mode');
        $this->assertStringContainsString('torn', $message);
        $b->counter('second', 2.0);
        fclose($stream);

        $lines = explode("\n", ShortWriteStream::$buf);
        $this->assertSame(120, strlen($lines[0]), 'exactly the faulted prefix, nothing past the block');
        $this->assertTrue(str_starts_with($lines[0], '{"ts":"'));
        $this->assertNull(json_decode($lines[0], true), 'the torn line is invalid JSON by construction');
        $decoded = json_decode((string) $lines[1], true);
        $this->assertIsArray($decoded, 'the quarantined successor must parse on its OWN line');
        $this->assertSame('second', $decoded['name']);
        $this->assertSame('', $lines[2], 'trailing newline keeps the file record-framed');
    }

    public function testTagsRenderByteIdenticalToTheHistoricObjectCast(): void
    {
        // sugar-metrics-047 replaced the per-record (object) $tags cast with
        // JSON_FORCE_OBJECT; every tags shape must render byte-identical,
        // including the two that a plain json_encode($tags) would change:
        // the empty map and numeric-looking keys.
        $stream = fopen('php://memory', 'w+');
        $b = new JsonStreamBackend($stream);
        $b->counter('empty', 1.0, []);
        $b->counter('numkey', 1.0, ['0' => 'a']);
        $b->counter('slashes', 1.0, ['route' => '/x']);
        rewind($stream);
        $body = (string) stream_get_contents($stream);
        fclose($stream);
        $this->assertStringContainsString('"tags":{}}', $body, 'empty tags stay an object, never []');
        $this->assertStringContainsString('"tags":{"0":"a"}}', $body, 'numeric-looking keys stay keys, never a list');
        $this->assertStringContainsString('"tags":{"route":"/x"}}', $body, 'slashes stay unescaped as before');
    }

    public function testOwnedEmitHoldsTheLockAcrossWriteFlushAndQuarantine(): void
    {
        // sugar-metrics-009(b): record-level flock + flush inside ONE lock
        // window on owned streams; injected resources take no lock (we
        // cannot claim a contract we do not own). Structural pin on the
        // emit() body — the two-process test below shows the wire result.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Backend/JsonStreamBackend.php');
        $from = (int) strpos($src, 'private function emit(');
        $body = substr($src, $from, (int) strrpos($src, '}') - $from);
        $this->assertStringContainsString('$this->owns && @flock($this->stream, LOCK_EX)', $body);
        $this->assertStringContainsString('@fwrite($this->stream, "\\n");', $body, 'the quarantine newline must be attempted');
        $this->assertStringContainsString('@fflush($this->stream);', $body, 'owned records flush inside the lock window');
        $this->assertStringContainsString('@flock($this->stream, LOCK_UN)', $body);
        $this->assertLessThan(
            (int) strpos($body, '@flock($this->stream, LOCK_UN)'),
            (int) strpos($body, '@fwrite($this->stream, "\\n");'),
            'quarantine belongs inside the lock window, after the short write'
        );
        $this->assertStringNotContainsString('flock($this->stream, LOCK_EX)', str_replace('$this->owns && @flock($this->stream, LOCK_EX)', '', $body), 'the lock may only be taken behind the owns guard');
    }

    public function testTwoOwnedProcessesAppendLineCleanRecords(): void
    {
        // sugar-metrics-009(b) wire result: two processes appending records
        // LARGER than the 8 KB stream buffer (multi-write(2) per flush —
        // the exact splice window) must still produce a file where every
        // line parses. Sibling shape of the Prometheus two-process test.
        $path = sys_get_temp_dir() . '/sugar-metrics-js-2proc-' . uniqid() . '.ndjson';
        $child = escapeshellarg(dirname(__DIR__, 2) . '/tests/Support/jsonstream-append-child.php');
        $autoload = escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php');
        $handles = [];
        try {
            foreach (['a', 'b'] as $tag) {
                $proc = proc_open(
                    PHP_BINARY . ' ' . $child . ' ' . $autoload . ' ' . escapeshellarg($path) . ' ' . $tag,
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );
                $this->assertIsResource($proc);
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $handles[] = $proc;
            }
            foreach ($handles as $proc) {
                $this->assertSame(0, proc_close($proc), 'append child must exit clean');
            }
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
            $this->assertCount(50, $lines, 'both processes contribute all 25 records');
            $tags = [];
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                $this->assertIsArray($decoded, 'no line may splice or tear: ' . substr($line, 0, 60));
                $tags[] = (string) $decoded['name'];
            }
            $this->assertSame(25, count(array_filter($tags, static fn(string $t): bool => $t === 'proc_a')));
            $this->assertSame(25, count(array_filter($tags, static fn(string $t): bool => $t === 'proc_b')));
        } finally {
            @unlink($path);
        }
    }

    public function testFlushOnAClosedInjectedStreamIsQuietInBothPostures(): void
    {
        // sugar-metrics-041: mirror of the StatsD closed-socket probe. The
        // stream is closed AFTER construction (the door validates the live
        // resource), so flush() sees a dead handle: the is_resource guard
        // short-circuits in BOTH postures — the loud throw is the documented
        // unreachable-but-loud safety net, not a behavior callers depend on.
        $stream = fopen('php://memory', 'w');
        $silent = new JsonStreamBackend($stream, throwOnError: false);
        $loud = new JsonStreamBackend($stream, throwOnError: true);
        fclose($stream);
        $silent->flush();
        $loud->flush();
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Backend/JsonStreamBackend.php');
        $this->assertStringContainsString('unreachable-but-loud', $src);
    }
}
