<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use React\EventLoop\Loop;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * Live-children registry backing {@see TracksActiveChildren} (CC-QA-2).
 *
 * Provider children are spawned by the command backends and reaped inside their
 * own drain loops. A quit, a Ctrl+C, or a double-Escape cancel can tear that
 * loop down while the command is still running: the direct child — the `setsid -w`
 * wrapper {@see \SugarCraft\Code\Support\ProcessContainment::spawnSpec()} builds —
 * and every process under it then outlive the session with nobody left to wait
 * for them. Each backend registers at its single spawn choke point and forgets
 * at every reap sink, so the interrupt arms and the exit funnel can abandon
 * exactly what is still alive. Both backends run their child through sugar-async's
 * ChildRun and register the pid reported by its onSpawn hook (registerActivePid),
 * with no proc handle held: the handle stays inside ChildRun, and the pid is all
 * abandon needs.
 *
 * The map is mutable shared state, the same shape as the CancellationToken
 * the backends already share across `mutate()` clones: a with*() copy carries
 * the same live children because they ARE the same live children.
 */
trait AbandonsActiveChildren
{
    /**
     * @var array<int, int> live children keyed by pid, and the pid is the value: killTree() walks /proc from the wrapper's pid
     */
    private array $activeChildren = [];

    /**
     * Record a freshly spawned child. The pid is the `setsid -w` wrapper's;
     * killTree() walks /proc from it and reaches the command and everything
     * below. A non-positive pid is refused by ProcessContainment anyway, so an
     * unnumbered child is simply not registered.
     */
    protected function registerActivePid(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        $this->activeChildren[$pid] = $pid;
    }

    protected function forgetActivePid(int $pid): void
    {
        unset($this->activeChildren[$pid]);
    }

    /**
     * @return list<int>
     */
    public function activeChildPids(): array
    {
        return array_values($this->activeChildren);
    }

    /**
     * Kill every registered tree, announce once when anything died.
     *
     * No reaping happens here: the children are abandoned, and an abandoned
     * process that outlives the parent is reparented, not waited on. The
     * SIGKILL-with-no-grace walk is bounded; a zombie that is already dead
     * holds its pid against reuse and takes the kill harmlessly.
     */
    public function abandonActiveChildren(bool $async = false): int
    {
        if ($this->activeChildren === []) {
            return 0;
        }

        $killed = 0;
        foreach ($this->activeChildren as $pid) {
            if ($async) {
                ProcessContainment::killTreeAsync($pid, Loop::get());
            } else {
                ProcessContainment::killTree($pid);
            }
            ++$killed;
        }
        $this->activeChildren = [];

        fwrite(STDERR, 'candy-code: ' . Lang::t('cli.quit.backend_children_abandoned', ['count' => $killed]) . PHP_EOL);

        return $killed;
    }
}
