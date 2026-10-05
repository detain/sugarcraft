<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Board;

/**
 * The board the delegated run executing in THIS process belongs to, and how
 * far into it that run has already been told about — the state
 * {@see \SugarCraft\Crush\Hooks\BuiltIn\BoardNoticeHook} reads.
 *
 * WHY PROCESS STATE. The notice hook is registered once, on the launch's
 * chain ({@see \SugarCraft\Crush\Cli\Bootstrap::hooks()}), and that chain is
 * shared by every run of the session; it sees a call's tool name and output,
 * never which run made it. What does tell the runs apart is the process: each
 * member of a parallel batch runs in a forked child of its own
 * ({@see \SugarCraft\Crush\Runtime::executeConcurrently()}), and its sub-agent's
 * PostToolUse chain runs in that same child. So the member's `Task` copy
 * {@see enter()}s its board as its run starts, and the hook asks this class.
 *
 * NESTED, NOT JUST SET. A member may delegate again (roadmap 4.7-3), and a
 * nested run that executes in the member's process is not on the board: it
 * enters `null`, and the seat it gets back restores the member's binding —
 * and its notice cursor — when the nested run ends. The seat is a local of the
 * run, so a throw restores it too ({@see ActiveBoardSeat}).
 */
final class ActiveBoard
{
    private static ?Board $board = null;

    /** The newest post id this process's run has been told about or has read. */
    private static int $noticed = 0;

    private function __construct()
    {
    }

    /**
     * Bind $board (null: none) for the run starting now, with nothing noticed
     * yet — a member that starts late still hears of what its peers posted
     * before it. Keep the returned seat alive for exactly as long as the run.
     */
    public static function enter(?Board $board): ActiveBoardSeat
    {
        $seat = new ActiveBoardSeat(self::$board, self::$noticed);
        self::$board = $board;
        self::$noticed = 0;

        return $seat;
    }

    /** The bound board, as the running member sees it, or null. */
    public static function current(): ?Board
    {
        return self::$board;
    }

    /** The newest post id the running member has been told about. */
    public static function noticed(): int
    {
        return self::$noticed;
    }

    /** Record that the running member has been told about (or has read) every post through $id. */
    public static function markNoticed(int $id): void
    {
        self::$noticed = max(self::$noticed, $id);
    }

    /**
     * Put back the binding a seat saved. Only {@see ActiveBoardSeat} calls it.
     *
     * @internal
     */
    public static function restore(?Board $board, int $noticed): void
    {
        self::$board = $board;
        self::$noticed = $noticed;
    }
}
