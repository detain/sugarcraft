<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * A check that throws once the process a delegated agent run started under is
 * gone — shared by {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} and
 * {@see \SugarCraft\Crush\Agents\EngineExecutor}, the two places a whole agentic
 * run executes inside a forked child.
 *
 * A forked run outlives the parent that forked it (the kernel signals nothing
 * to a child when its parent is SIGKILLed), and an orphaned agent loop would go
 * on editing the tree for nobody. The teardown paths that kill a turn on
 * purpose — EngineBackend's cancel/idle teardown and Runtime's parallel
 * deadline — now take the whole tree down through
 * {@see ProcessContainment::killTree()} (audit B2/F-E2), so this guard is the
 * BACKSTOP for the deaths no teardown ran for (a parent that crashed, a host
 * without /proc where killTree() degrades to the direct kill) and for the fork
 * sites that do not call killTree() yet. Call the returned closure from
 * PHP-level progress sinks only — never from a provider's transport callback,
 * where a throw would unwind through libcurl — so the run stops cleanly at its
 * next chunk or tool event.
 */
final class ParentProcessGuard
{
    /**
     * @param string $who what the run belonged to, for the abandonment message
     *
     * @return \Closure(): void
     */
    public static function capture(string $who): \Closure
    {
        if (!function_exists('posix_getppid')) {
            return static function (): void {};
        }

        $parent = posix_getppid();

        return static function () use ($parent, $who): void {
            if (posix_getppid() !== $parent) {
                throw new \RuntimeException("the {$who} is gone (parent process exited), so the run was abandoned");
            }
        };
    }
}
