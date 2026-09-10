<?php

declare(strict_types=1);

/*
 * E295 drain scenario for
 * AgentWorkerPoolTest::testForkedWorkerDrainsInheritedOutputBufferWithoutDoublePrintingOrLosingParentOutput.
 *
 * Runs the pool's REAL fork path inside an isolated script process so the
 * child's inherited stdout is a proc_open pipe the test can read. Sequence:
 *
 *   1. ob_start() + echo marker -> the SCRIPT's own output buffer holds the
 *      parent-side session bytes; nothing has reached fd 1 yet.
 *   2. executeAll() forks a worker -> the CHILD inherits that live buffer.
 *   3. Child: storeResult() -> drain (ob_end_clean loop) -> exit(0).
 *      With the drain the child leaves silently — its inherited copy is
 *      discarded. Without it, PHP's shutdown FLUSHES the inherited buffer to
 *      the shared fd 1 and the marker reaches the pipe a second time: the
 *      E229-class double-print from the wrong process.
 *   4. Script: ob_end_flush() -> its own copy prints exactly once. If the
 *      parent-side bytes were ever lost the count drops to zero.
 *
 * The test asserts substr_count(stdout, marker) === 1 — one assertion pinning
 * BOTH polarities (no double-print, no lost parent output). Mirrors the
 * BackgroundSessionRunner exitWorker() drain shape under test.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;

// Bound the scenario: if the pool ever fails to settle, SIGALRM ends this
// process and the test fails on the missing DONE line instead of hanging.
pcntl_async_signals(true);
pcntl_alarm(90);

$request = new CompleteRequest(
    model: 'test-model',
    messages: [['role' => 'user', 'content' => 'Hello!']],
);

$agent = new SubAgent(
    id: 'e295-drain',
    agent: new Agent(
        name: 'DrainProbe',
        description: 'E295 drain scenario agent',
        prompt: 'You are a test agent.',
        model: 'test-model',
        provider: 'test',
        tools: [],
        skillNames: [],
        hooks: [],
        isActive: true,
    ),
    task: 'E295 inherited-buffer drain probe',
);

ob_start();
echo "SCENARIO-PARENT-OUTPUT\n";

// workerProvider, NOT an injected executor: an ExecutorInterface would set
// $customExecutor and route the dispatch synchronously in THIS process, and
// the forked child branch carrying the drain under test would never run.
$pool = new AgentWorkerPool(maxConcurrent: 1, workerProvider: ['type' => 'echo']);

$status = null;
foreach ($pool->executeAll([$agent], $request) as $result) {
    $status = $result->status;
}

ob_end_flush();

fwrite(STDERR, 'DONE:' . ($status?->name ?? 'null') . "\n");
exit($status?->name === 'Completed' ? 0 : 1);
