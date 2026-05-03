<?php

declare(strict_types=1);

namespace CandyCore\Stash;

/**
 * Thin wrapper around `git` invocations. Everything that mutates a
 * repo shells out — no libgit2 binding, no in-PHP plumbing-command
 * reimplementation. The wrapper is split out so tests can swap in a
 * fixture-backed `Git` (or its parent interface, {@see GitDriver})
 * without touching the runtime.
 */
final class Git implements GitDriver
{
    public function __construct(public readonly string $cwd)
    {}

    public function status(): array
    {
        $out = $this->run(['status', '--porcelain=v1', '-b']);
        $rows = [];
        foreach ($out as $line) {
            if ($line === '') continue;
            if (str_starts_with($line, '##')) {
                $rows[] = ['branch_summary' => trim(substr($line, 2))];
                continue;
            }
            $rows[] = [
                'index_status'   => $line[0] ?? ' ',
                'work_status'    => $line[1] ?? ' ',
                'path'           => substr($line, 3),
            ];
        }
        return $rows;
    }

    public function branches(): array
    {
        $out = $this->run([
            'for-each-ref', '--format=%(HEAD) %(refname:short)\t%(objectname:short)',
            'refs/heads',
        ]);
        $rows = [];
        foreach ($out as $line) {
            if ($line === '') continue;
            $head    = str_starts_with($line, '*');
            $payload = ltrim(substr($line, 2));
            [$name, $sha] = array_pad(explode("\t", $payload, 2), 2, '');
            $rows[] = ['name' => $name, 'sha' => $sha, 'current' => $head];
        }
        return $rows;
    }

    public function log(int $limit = 25): array
    {
        $out = $this->run([
            'log', '--pretty=format:%h%x09%s%x09%an%x09%ar', "-n{$limit}",
        ]);
        $rows = [];
        foreach ($out as $line) {
            if ($line === '') continue;
            [$sha, $subject, $author, $ago] = array_pad(explode("\t", $line, 4), 4, '');
            $rows[] = compact('sha', 'subject', 'author', 'ago');
        }
        return $rows;
    }

    public function stage(string $path): void
    {
        $this->run(['add', '--', $path]);
    }

    public function unstage(string $path): void
    {
        $this->run(['restore', '--staged', '--', $path]);
    }

    /** @return list<string> */
    private function run(array $args): array
    {
        $cmd = array_merge(['git', '-C', $this->cwd], $args);
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('git: failed to spawn');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new \RuntimeException("git: " . trim($stderr));
        }
        return explode("\n", rtrim($stdout, "\n"));
    }
}
