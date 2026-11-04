<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * mi6a (lib-reviews-1102): `record --output tape.cas.gz` happily produced a
 * PLAIN JSONL file under a .gz name — Recorder::open() writes bytes, gzip
 * lives only in CompressedJsonlFormat (the API). The CLI now refuses the
 * lying artifact at the door, before any spawn or truncation side effect.
 */
final class RecordGzRefusalTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function gzSpellings(): array
    {
        return [
            'lowercase' => ['session.cas.gz'],
            'uppercase' => ['session.CAS.GZ'],
            'bare-gz'   => ['session.gz'],
        ];
    }

    /**
     * @dataProvider gzSpellings
     */
    public function testGzOutputIsRefusedBeforeAnySideEffect(string $name): void
    {
        $dir = \sys_get_temp_dir() . '/cv-recgz-' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0700);
        $path = $dir . '/' . $name;

        $cmd = new RecordCommand(Stream::devNull());
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        try {
            $rc = $cmd->run(['--output', $path, '--', '/bin/echo', 'unused'], $stdout, $stderr);
            rewind($stderr);
            $err = (string) \stream_get_contents($stderr);

            $this->assertSame(2, $rc, $err);
            $this->assertStringContainsString('cannot be a .gz path', $err);
            $this->assertFalse(\file_exists($path), 'refusal must not create the output file');
        } finally {
            \fclose($stdout);
            \fclose($stderr);
            @\unlink($path);
            @\rmdir($dir);
        }
    }

    public function testPlainNameStillPassesTheDoor(): void
    {
        $dir = \sys_get_temp_dir() . '/cv-recgz-' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0700);
        $path = $dir . '/session.cas';

        $cmd = new RecordCommand(Stream::devNull());
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        try {
            $rc = $cmd->run(['--output', $path, '--', '/bin/echo', 'ok'], $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);
            $out = (string) \stream_get_contents($stdout) . (string) \stream_get_contents($stderr);

            // The .gz sentence must not appear: whatever the pty environment
            // decides next, it is not THIS door refusing.
            $this->assertStringNotContainsString('cannot be a .gz path', $out);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
            @\unlink($path);
            @\rmdir($dir);
        }
    }
}
