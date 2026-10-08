<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\ProcRow;

/**
 * Column widths of a btop process row:
 *
 *   pid(8)␠ name(prog)␠ cmd(cmd)␠ threads(t)␠ user(u)␠ mem(5)␠ graph(5)␠ cpu(4)␠␠
 *
 * cmd and threads are dropped when their width is ≤ 0 (btop hides them on
 * narrow boxes); the graph column collapses to its trailing separator
 * when cpu graphs are off. {@see btop()} reproduces btop's own sizing so
 * a caller that only knows the box width gets btop's layout; a caller
 * with its own policy passes widths to {@see new()}.
 *
 * Mirrors aristocratos/btop Proc::draw's size block (user_size /
 * thread_size / prog_size / cmd_size, src/btop_draw.cpp).
 */
final class ProcColumns
{
    public const PID = 8;
    public const MEM = 5;
    public const GRAPH = 5;
    public const CPU = 4;

    private function __construct(
        public readonly int $prog,
        public readonly int $cmd,
        public readonly int $threads,
        public readonly int $user,
        public readonly bool $cpuGraphs,
    ) {
    }

    /**
     * @param int $cmd     ≤ 0 hides the command column
     * @param int $threads ≤ 0 hides the threads column
     * @throws \InvalidArgumentException on a negative program width or a user width < 1
     */
    public static function new(int $prog, int $cmd, int $threads, int $user, bool $cpuGraphs = true): self
    {
        if ($prog < 0 || $user < 1) {
            throw new \InvalidArgumentException(sprintf(
                'ProcColumns needs prog >= 0 and user >= 1, got prog=%d user=%d',
                $prog,
                $user,
            ));
        }
        return new self($prog, $cmd, $threads, $user, $cpuGraphs);
    }

    /**
     * btop's widths for a proc box `$boxWidth` cells wide (borders
     * included). The rows then fill `$boxWidth - 2` cells, except below 56
     * columns where btop's own arithmetic leaves one cell unwritten — the
     * composer pads that slack.
     *
     * @throws \InvalidArgumentException when the box is too narrow for any program column
     */
    public static function btop(int $boxWidth, bool $cpuGraphs = true): self
    {
        $user = $boxWidth < 75 ? 5 : 10;
        $threads = $boxWidth < 75 ? -1 : 4;
        $prog = $boxWidth > 70 ? 16 : ($boxWidth > 55 ? 8 : $boxWidth - $user - $threads - 33);
        $cmd = $boxWidth > 55 ? $boxWidth - $prog - $user - $threads - 33 : -1;
        if (!$cpuGraphs) {
            // btop adds the freed graph cells to cmd even when cmd was
            // hidden (-1 → 4), which is what re-shows it on narrow boxes.
            $cmd += 5;
        }
        return self::new($prog, $cmd, $threads, $user, $cpuGraphs);
    }

    /** Cells one row occupies, separators included. */
    public function width(): int
    {
        return self::PID + 1
            + $this->prog + 1
            + ($this->cmd > 0 ? $this->cmd + 1 : 0)
            + ($this->threads > 0 ? $this->threads + 1 : 0)
            + $this->user + 1
            + self::MEM + 1
            + ($this->cpuGraphs ? self::GRAPH : 0) + 1
            + self::CPU + 2;
    }
}
