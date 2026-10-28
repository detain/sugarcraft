<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * C-WEB-4.f (GAP-GIT-01) — the session summary carries the branch the
 * session was OPENED on.
 *
 * The `sessions` row has recorded `git_branch` since long ago (the launch
 * writes it, a child inherits it), but `SessionSummary` never published it,
 * so no web surface could show where a session began. The fill is a pure
 * read off the row: nothing on the serve path shells out to git, which is
 * exactly why the value means "branch at session start" and not "current
 * checkout" — an agent that runs `git checkout` mid-session leaves the row
 * (and this field) where they started.
 *
 * @see \SugarCraft\Code\Protocol\ServerContext::summary()
 */
final class SessionSummaryBranchTest extends TestCase
{
    private ?ProtocolFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->tearDown();
        $this->fixture = null;
        parent::tearDown();
    }

    public function testTheSummaryCarriesTheBranchTheRowRecorded(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $fixture->store->createSession('started', 'p', 'm', gitBranch: 'feature/weld-8');

        $summary = $fixture->context->summaryOf('started');

        self::assertArrayHasKey('branch', $summary, 'the key rides every summary, not only ones with a branch');
        self::assertSame('feature/weld-8', $summary['branch']);
    }

    public function testASessionStartedOffAnyNamedBranchCarriesNull(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $fixture->store->createSession('bare', 'p', 'm');

        $summary = $fixture->context->summaryOf('bare');

        self::assertArrayHasKey('branch', $summary);
        self::assertNull($summary['branch'], 'unset row column, null field — never an invented name');
    }

    public function testTheBranchReachesTheWireThroughSessionList(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $fixture->store->createSession('with-branch', 'p', 'm', gitBranch: 'release/1.0');
        $fixture->store->createSession('without', 'p', 'm');

        $items = $fixture->client()->call('session.list', [])['items'];
        $byId = [];
        foreach ($items as $item) {
            $byId[(string) $item['id']] = $item;
        }

        self::assertSame('release/1.0', $byId['with-branch']['branch'], 'the web reads this key straight off the wire');
        self::assertNull($byId['without']['branch']);
    }

    public function testAnOpenHostDoesNotInventABranchTheRowNeverHad(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();
        $id = $fixture->session($client);

        $summary = $fixture->context->summaryOf($id);

        self::assertTrue($summary['open'], 'the host is open…');
        self::assertNull($summary['branch'], '…and still no live git read fabricates a branch for it');
    }
}
