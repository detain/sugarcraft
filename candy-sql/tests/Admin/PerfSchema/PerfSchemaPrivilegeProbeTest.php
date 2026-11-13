<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\PerfSchema\PerfSchemaPage;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-P01 (adminpages-001) call-count spy: the UPDATE-privilege probe ran
 * through the SYNC handle on EVERY rendered frame and navigation keystroke
 * — a full write round trip per tick against non-localhost servers, for a
 * session-constant boolean. It must now fire once per page session.
 *
 * Can-fail proof: remove the memo in hasUpdatePrivilege() and frame 2
 * trips the count assertion.
 */
final class PerfSchemaPrivilegeProbeTest extends TestCase
{
    private const PROBE_NEEDLE = 'SET `ENABLED` = `ENABLED` WHERE 1=0';

    private function probeCount(FakeDatabase $db): int
    {
        $n = 0;
        foreach ($db->execLog() as $sql) {
            if (str_contains($sql, self::PROBE_NEEDLE)) {
                $n++;
            }
        }
        return $n;
    }

    public function testProbeFiresOnceAcrossTenFramesAndAKeystrokeBurst(): void
    {
        $db = new FakeDatabase();
        $page = new PerfSchemaPage(new ServerContext($db));

        for ($frame = 0; $frame < 10; $frame++) {
            $page->view();
        }
        foreach (['j', 'j', 'k', 'h', 'l', "\t"] as $rune) {
            [$page, ] = $page->update(new KeyMsg(KeyType::Char, $rune));
            $page->view();
        }

        $this->assertSame(1, $this->probeCount($db), 'exactly one probe per page session');
    }

    public function testFreshPageAfterReconnectProbesAgain(): void
    {
        $first = new FakeDatabase();
        (new PerfSchemaPage(new ServerContext($first)))->view();
        $this->assertSame(1, $this->probeCount($first));

        $rebuilt = new FakeDatabase();
        (new PerfSchemaPage(new ServerContext($rebuilt)))->view();
        $this->assertSame(1, $this->probeCount($rebuilt), 'a new context/page re-probes once');
    }

    public function testForgetHookForcesReProbe(): void
    {
        $db = new FakeDatabase();
        $page = new PerfSchemaPage(new ServerContext($db));
        $page->view();
        $page->forgetPrivilegeProbe();
        $page->view();

        $this->assertSame(2, $this->probeCount($db), 'explicit invalidation re-probes');
    }
}
