<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;

/**
 * A PHPUnit extension that resets {@see RuntimeNoticeSink} before every test.
 *
 * WHY THIS EXISTS AT ALL (E194): the sink is a process-wide static — armed by
 * anything that reaches `Bootstrap::chat()`, filled by the parser thread's
 * notices, and readable by any later test in the same process. Round 47's fix
 * was an appointment (`Chat::drainsRuntimeNotices`), which made the leak
 * unreachable through the known route but left the general shape standing:
 * "clean at your start" was still a property of who happens to drain, not a
 * guarantee of the harness. This extension makes it structural. Every test
 * begins with an un-armed sink, an empty queue and no live transport, whatever
 * the alphabet of arming routes turns out to be as `E192` lands.
 *
 * WHY `PreparationStarted` AND NOT `Prepared`: the reset has to be visible to
 * `setUp()` itself, not merely to the test body — a test that arms the sink in
 * `setUp()` and one that asserts emptiness in `setUp()` both run after this
 * event and before the next one. Finer than the row's "per test case": the
 * round-47 leak it replaces was measured BETWEEN CASES, and per-case timing
 * would fix that, but a notice queued by test N's provider or its sibling
 * method would still ride into test N+1 under a per-case reset. Per-test is
 * the bound the appointment cannot promise, and it costs one static write on
 * a path that runs ~11,600 times.
 *
 * WHY REGISTERED FROM `phpunit.xml` AND NOT FROM `tests/bootstrap.php`: the
 * bootstrap already registers the skip roster through `EventFacade` directly,
 * and copying that idiom would work. It would also make the reset contingent
 * on every runner loading this file — the forked children that `require` it
 * would re-arm the subscriber in processes whose event facade never seals,
 * while `phpunit.xml` registration reaches exactly the one process that emits
 * test events. The `<extensions>` block the row names as missing is also the
 * discoverable home: a reader of the config sees the guarantee is installed
 * without having to know to read the bootstrap.
 *
 * IT MUST NOT BECOME AN ASSERTION'S SUBJECT BY ACCIDENT: this class calls
 * `reset()`, never `warn()`, so it adds no emitter to the stderr census, and
 * it names no `T_*` constant or bare brace pair, so no token walker selects
 * it. Its pinning is registration itself — see
 * `tests/Diagnostics/RuntimeNoticeSinkResetExtensionTest.php`.
 */
final class RuntimeNoticeSinkResetExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                RuntimeNoticeSink::reset();
            }
        });
    }
}
