<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * proc_open descriptor specs for a relay that must WRITE to a child's stdin
 * and read its stdout under a select loop, on every host — for children
 * whose runtime can use a socket as stdio.
 *
 * On Windows a proc_open PIPE cannot be made non-blocking, select() reports
 * it ready while empty, a read parks until the child writes or exits, and a
 * write bigger than the 4096-byte pipe buffer blocks until the child reads
 * (and fails outright, every byte lost, if the child dies first). Reads can
 * be made safe by polling ({@see WindowsPipes}); writes cannot. A proc_open
 * SOCKET has none of that: non-blocking works, select() is honest, partial
 * writes report their count, and EOF arrives when the child closes.
 *
 * BUT only some Windows children can use a socket stdio. Measured (PHP
 * 8.4.27): php.exe, git.exe and where.exe read and write it like a pipe;
 * node.exe, python.exe and cmd.exe silently lose their output, and
 * MSYS/Cygwin tools (Git-for-Windows sh, bash, cat) fail to read stdin or
 * redirect an fd. So use this ONLY for a child known to cope (the git
 * services); an arbitrary child keeps pipes and a polling reader
 * ({@see ChildPump::drain()} with a `running:` probe).
 *
 * stderr stays a PIPE even on Windows: a child's own children inherit it (git
 * runs repository hooks through MSYS sh), and those must keep working. Read
 * it with {@see WindowsPipes::readAvailable()} there, never select().
 */
final class ChildStdio
{
    /** Whether {@see descriptors()} hands out sockets for stdin/stdout on this runtime. */
    public static function usesSockets(): bool
    {
        return \PHP_OS_FAMILY === 'Windows';
    }

    /**
     * stdin/stdout/stderr specs: on Windows sockets for 0 and 1 and a pipe for
     * 2; plain pipes elsewhere.
     *
     * @param list<int> $fds which of 0, 1, 2 to include
     * @return array<int, array{0: string, 1?: string}>
     */
    public static function descriptors(array $fds = [0, 1, 2]): array
    {
        $specs = [];
        foreach ($fds as $fd) {
            $specs[$fd] = self::usesSockets() && $fd !== 2
                ? ['socket']
                : ['pipe', $fd === 0 ? 'r' : 'w'];
        }

        return $specs;
    }

    /**
     * Whether $stream is a socket (selectable and non-blocking capable on
     * every host) rather than a Windows-hostile pipe.
     *
     * @param resource $stream
     */
    public static function isSocket($stream): bool
    {
        if (!\is_resource($stream)) {
            return false;
        }
        $type = (string) (\stream_get_meta_data($stream)['stream_type'] ?? '');

        return \str_contains($type, 'socket');
    }
}
