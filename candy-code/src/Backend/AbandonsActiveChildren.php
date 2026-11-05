<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use React\EventLoop\Loop;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * Live-children registry backing {@see TracksActiveChildren} (CC-QA-2).
 *
 * Both command backends spawn provider children through `proc_open()` and
 * reap them inside their own drain loops. A quit, a Ctrl+C, or a double-
 * Escape cancel can tear that loop down while the command is still running:
 * the direct child — the `setsid -w` wrapper
 * {@see \SugarCraft\Code\Support\ProcessContainment::spawnSpec()} builds —
 * and every process under it then outlive the session with nobody left to
 * wait for them. Each backend registers at its single spawn choke point and
 * forgets at every reap sink, so the interrupt arms and the exit funnel can
 * abandon exactly what is still alive.
 *
 * The map is mutable shared state, the same shape as the CancellationToken
 * the backends already share across `mutate()` clones: a with*() copy carries
 * the same live children because they ARE the same live children.
 */
trait AbandonsActiveChildren
{
    /**
     * @var array<int, array{pid: int, proc: resource}> keyed by proc resource id
     */
    private array $activeChildren = [];

    /**
     * Record a freshly spawned child. The pid proc_open reports is the
     * wrapper's; killTree() walks /proc from it and reaches the command and
     * everything below. A non-positive pid is refused by ProcessContainment
     * anyway, so an unnumbered child is simply not registered.
     *
     * @param resource $proc
     */
    protected function registerActiveChild($proc): void
    {
        $pid = proc_get_status($proc)['pid'];
        if ($pid <= 0) {
            return;
        }

        $this->activeChildren[(int) $proc] = ['pid' => $pid, 'proc' => $proc];
    }

    /**
     * Remove a child the backend has just reaped itself.
     *
     * @param resource $proc
     */
    protected function forgetActiveChild($proc): void
    {
        unset($this->activeChildren[(int) $proc]);
    }

    /**
     * @return list<int>
     */
    public function activeChildPids(): array
    {
        return array_map(
            static fn (array $child): int => $child['pid'],
            array_values($this->activeChildren),
        );
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
        foreach ($this->activeChildren as $child) {
            if ($async) {
                ProcessContainment::killTreeAsync($child['pid'], Loop::get());
            } else {
                ProcessContainment::killTree($child['pid']);
            }
            ++$killed;
        }
        $this->activeChildren = [];

        fwrite(STDERR, 'candy-code: ' . Lang::t('cli.quit.backend_children_abandoned', ['count' => $killed]) . PHP_EOL);

        return $killed;
    }
}
