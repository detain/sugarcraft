<?php

declare(strict_types=1);

namespace CandyCore\Tick;

/**
 * JSONL-backed heartbeat store. One file per day under
 * `~/.local/share/sugar-tick/YYYY-MM-DD.jsonl` — the path stays
 * appendable, every editor plug-in just `>>` echoes a JSON line.
 *
 * The Store is intentionally read-only at v1 — writes happen via the
 * `tick:push` CLI subcommand or by your editor plugin appending
 * directly. Keeps the dashboard model simple (no race with editor
 * writes) and lets an old-school cron job ship the file off to
 * cold storage without locking concerns.
 */
final class Store
{
    public function __construct(public readonly string $dir)
    {}

    /** Default per-user directory (XDG / fallback to ~). */
    public static function defaultDir(): string
    {
        $xdg = getenv('XDG_DATA_HOME');
        if ($xdg !== false && $xdg !== '') {
            return rtrim($xdg, '/') . '/sugar-tick';
        }
        return rtrim(getenv('HOME') ?: '.', '/') . '/.local/share/sugar-tick';
    }

    /** @return list<Heartbeat> */
    public function loadDay(\DateTimeImmutable $day): array
    {
        $file = $this->dir . '/' . $day->format('Y-m-d') . '.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $rows = [];
        foreach (explode("\n", (string) file_get_contents($file)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = Heartbeat::fromArray($decoded);
            }
        }
        return $rows;
    }

    /**
     * Load every heartbeat in [$from, $to] — date range inclusive.
     *
     * @return list<Heartbeat>
     */
    public function loadRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = [];
        $cur = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);
        while ($cur <= $end) {
            $rows = array_merge($rows, $this->loadDay($cur));
            $cur  = $cur->modify('+1 day');
        }
        return $rows;
    }

    public function append(Heartbeat $hb): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
        $file = $this->dir . '/' . date('Y-m-d', $hb->time) . '.jsonl';
        $line = json_encode($hb->toArray(), JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($file, $line, FILE_APPEND);
    }
}
