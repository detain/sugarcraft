<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use React\Promise\PromiseInterface;

/**
 * Strategy interface for asynchronous rendering.
 *
 * E722 (round 82) posture, judged from the tree: this library ships ONE
 * implementation — {@see SyncAsyncRenderer}, a futureTick deferral that
 * owns no child process. The worker-pool and pcntl_fork-per-render
 * backends named below are design invitations, not shipped code, and the
 * E722 audit note about a "64 MiB stderr cap" has no referent here at all
 * (erratum: no such cap exists anywhere in candy-mosaic or its history —
 * the only external children in the lib are {@see \SugarCraft\Mosaic\Renderer\ChafaRenderer}'s
 * probe and render: render() spawns with fd 2 inherited; the probe's
 * transient fd-2 pipe is closed before reaping — nothing buffers at either
 * site, so no cap is warranted).
 *
 * Implement this to provide alternate async backends (e.g. a worker pool
 * or pcntl-fork-based process per render) — and inherit the tree's
 * child-lifetime obligations when you do:
 *
 *  - OWNER-REAP: every spawned child is reaped on every settle path of
 *    the promise — resolve, reject, and abandonment. An in-flight render
 *    must die with the renderer, never outlive it (TERM→poll→KILL
 *    bounded ladder; sugar-reel's Support\BoundedReaper spells out the
 *    rungs).
 *  - NO READER-LESS PIPES: drain or file-sink every child descriptor. A
 *    writer blocked on a full ~64KB kernel buffer is a child that never
 *    exits — the sugar-reel F7 deadlock shape; ChafaRendererChildTest
 *    pins it behaviourally for the shipped sites.
 *  - PUMP LAW (r69): a wait loop built on pump/poll carries no internal
 *    deadline — the CALLER bounds. Do not park a render promise's
 *    resolution on an unbounded read of a live child.
 *  - FD CENSUS: if you prove descriptor hygiene on Linux ≥ 6.6, key fd
 *    identity by /proc/<pid>/fdinfo fields (e.g. eventfd-id), NOT by
 *    stat (dev, ino) — since the anon_inodefs consolidation every
 *    anonymous object (eventfd/eventpoll/inotify) shares ONE (dev, ino),
 *    so a dev/ino census false-matches unrelated fds
 *    (see tests/SsrfServerLeakTest::descriptorIdentities).
 */
interface AsyncRenderer
{
    /**
     * Render the image asynchronously and resolve with ANSI bytes.
     *
     * @return PromiseInterface<string>  Resolves with encoded bytes on success.
     */
    public function renderAsync(ImageSource $image, int $width, int $height): PromiseInterface;
}
