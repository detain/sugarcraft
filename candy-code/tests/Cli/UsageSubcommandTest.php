<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Usage;

/**
 * C-SES-8.e — `candy-code usage [7|30|all] [--session <target>]`, the
 * headless arm of the `/usage` page: the same
 * {@see \SugarCraft\Code\Host\Commands\UsageHostCommand::rollupFacts()}
 * snapshot folded by the same {@see \SugarCraft\Code\Commands\UsageCommand::compose()},
 * so the TUI, the usage RPC and this verb cannot disagree about one ledger.
 *
 * The door order the arm commits to, each pin below naming one door: a junk
 * window word and a second operand are usage doors (exit 2 — nothing was
 * attempted); an unknown `--session` target exits 1 typed `not-found` (the
 * store WAS opened and queried — the same attempted/not-attempted
 * distinction every session verb keeps, reached here through the shared
 * resolver the positional session verbs use); an ambiguous prefix is an
 * exit-2 usage door naming the candidates; a store that will not open prints
 * a reason page, never a silent zero.
 *
 * In-process, the way SessionImportSubcommandTest drives its verb:
 * ArgvParser → Subcommands::dispatch with `HOME` pointed at a private
 * directory. stdout is ob-captured; stderr is not capturable in-process, so
 * every failure arm is asserted through the `--output-format json` document.
 */
final class UsageSubcommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;
    private string|false $originalRetention;
    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->home = (string) realpath(sys_get_temp_dir()) . '/csu8e-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->home . '/.candy-code', 0700, true));
        chmod($this->home, 0700);
        $this->originalRetention = getenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        $this->useHomeSandbox($this->home);
        putenv('CANDY_CODE_SESSION_RETENTION_DAYS');

        $this->store = new EnhancedSessionStore($this->home . '/.candy-code/session.db');
        $this->store->createSession('sess-main-0001', 'openai', 'gpt-4o', null, 'login work');
        $this->store->createSession('sess-foreign-0002', 'anthropic', 'claude-3', null, 'other work');
        $this->store->appendUsageRecord('sess-main-0001', Usage::new(1000, 0.02), 'gpt-4o');
        $this->store->appendUsageRecord('sess-main-0001', Usage::new(10, 0.0, unpricedModel: 'mystery-model'), 'mystery-model');
        $this->store->appendUsageRecord('sess-foreign-0002', Usage::new(500, 0.5), 'claude-3');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        putenv($this->originalRetention === false
            ? 'CANDY_CODE_SESSION_RETENTION_DAYS'
            : 'CANDY_CODE_SESSION_RETENTION_DAYS=' . $this->originalRetention);
        $this->purgeSandbox($this->home);
    }

    private function purgeSandbox(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }

    // ── the text page ────────────────────────────────────────────────────

    public function testTheTextPageFoldsEverySessionWithBothAmountShapes(): void
    {
        [$rc, $out] = $this->runUsage(['usage']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);

        self::assertStringContainsString('Scope: all sessions', $out);
        self::assertStringContainsString('gpt-4o', $out);
        self::assertStringContainsString('claude-3', $out);
        // Priced spend prints a bare $ figure; the total is 0.02 + 0.5.
        self::assertStringContainsString('$0.5200', $out);
        // The window line has no business on the no-window page.
        self::assertStringNotContainsString('Window: last', $out);
    }

    public function testAnUnpricedModelFoldsToALowerBoundNeverABareDollar(): void
    {
        [$rc, $out] = $this->runUsage(['usage']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);

        // The mystery-model fold carries ≥$ and the footer names the law.
        self::assertStringContainsString('mystery-model', $out);
        self::assertStringContainsString('≥$', $out);
        self::assertStringContainsString('Dollars shown with ≥ are lower bounds', $out);

        // The unpriced group's own row never shows a bare-$ spend figure:
        // strip every ≥$ cell first, then the surviving grid rows may not
        // carry mystery-model beside a plain $.
        $stripped = str_replace('≥$0.0000', 'BOUND', $out);
        foreach (explode("\n", $stripped) as $line) {
            if (str_contains($line, 'mystery-model')) {
                self::assertStringNotContainsString('$0.0000', $line, $out);
            }
        }
    }

    public function testTheWindowOperandPrintsItsLineAndTheSessionFlagNarrowsTheFold(): void
    {
        [$rc, $out] = $this->runUsage(['usage', '7']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        self::assertStringContainsString('Window: last 7 whole UTC days, today included', $out);

        [$rc, $out] = $this->runUsage(['usage', '30', '--session', 'sess-main-0001']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        self::assertStringContainsString('Window: last 30 whole UTC days, today included', $out);
        self::assertStringContainsString('Scope: this session', $out);
        // The foreign session's model drops out of the narrowed fold...
        self::assertStringNotContainsString('claude-3', $out);
        // ...while both of the scoped session's groups stay.
        self::assertStringContainsString('gpt-4o', $out);
        self::assertStringContainsString('mystery-model', $out);
    }

    // ── the JSON document ────────────────────────────────────────────────

    public function testTheJsonFormCarriesTheFactsMapVerbatim(): void
    {
        [$rc, $out] = $this->runUsage(['usage', '--output-format', 'json', '7']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        $decoded = json_decode(trim($out), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$out}");

        $facts = $decoded['result']['usage'] ?? null;
        self::assertIsArray($facts, $out);
        self::assertSame(['scopeAll', 'windowDays', 'days', 'models', 'reason'], array_keys($facts));
        self::assertTrue($facts['scopeAll']);
        self::assertSame(7, $facts['windowDays']);
        self::assertSame('', $facts['reason']);

        $day = $facts['days'][0] ?? null;
        self::assertIsArray($day, $out);
        self::assertSame(['day', 'model', 'calls', 'totalTokens', 'costUsd', 'unpriced'], array_keys($day));
        self::assertContains('gpt-4o', array_column($facts['models'], 'model'));

        [$rc, $out] = $this->runUsage(['usage', '--output-format', 'json', '--session', 'sess-main-0001']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $out);
        $scoped = json_decode(trim($out), true)['result']['usage'];
        self::assertFalse($scoped['scopeAll']);
        self::assertNull($scoped['windowDays']);
        self::assertNotContains('claude-3', array_column($scoped['models'], 'model'));
    }

    // ── the store that will not open ─────────────────────────────────────

    public function testAMissingStorePrintsAReasonAndNeverASilentZero(): void
    {
        // Replace the ledger with garbage AFTER seeding: the verb opens its
        // own fresh connection, and the ctor must refuse the file. The WAL
        // sidecars go first — live WAL frames can otherwise let SQLite serve
        // the old commits through an overwritten main file.
        foreach (['session.db-wal', 'session.db-shm'] as $sidecar) {
            @unlink($this->home . '/.candy-code/' . $sidecar);
        }
        self::assertNotFalse(file_put_contents($this->home . '/.candy-code/session.db', 'not a database'));

        [$rc, $out] = $this->runUsage(['usage']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $out);
        self::assertStringContainsString('Durable ledger: unavailable —', $out);
        // The reason page is not the empty-ledger page: no grid, and never
        // the 'Totals ... $0.0000' row a silent zero would print.
        self::assertStringNotContainsString('By day (UTC):', $out);
        self::assertStringNotContainsString('Totals:', $out);

        [$rc, $out] = $this->runUsage(['usage', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $out);
        $facts = json_decode(trim($out), true)['result']['usage'];
        self::assertNull($facts['days']);
        self::assertNull($facts['models']);
        self::assertNotSame('', $facts['reason']);
    }

    // ── the doors ────────────────────────────────────────────────────────

    public function testABadWindowWordIsAUsageDoorNamingTheContract(): void
    {
        $message = $this->refusedUsageDoor(['usage', 'monday']);
        self::assertStringContainsString('usage monday', $message);
        self::assertStringContainsString('must be 7, 30 or all', $message);
    }

    public function testASecondOperandIsTheUnexpectedOperandDoor(): void
    {
        $message = $this->refusedUsageDoor(['usage', '7', '30']);
        self::assertStringContainsString('30', $message);
        self::assertStringContainsString('unexpected operand', $message);
    }

    public function testAnUnknownTargetExitsOneTypedNotFound(): void
    {
        [$rc, $out] = $this->runUsage(['usage', '--session', 'nope-xyz', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc, $out);
        $decoded = json_decode(trim($out), true);
        self::assertSame('not-found', $decoded['error']['type'] ?? null, $out);
        self::assertStringContainsString('nope-xyz', (string) ($decoded['error']['message'] ?? ''));
    }

    public function testAnAmbiguousPrefixIsAUsageDoorNamingTheCandidates(): void
    {
        $this->store->createSession('deadbeef0001', 'openai', 'gpt-4o');
        $this->store->createSession('deadbeef0002', 'openai', 'gpt-4o');

        $message = $this->refusedUsageDoor(['usage', '--session', 'deadbeef']);
        self::assertStringContainsString('ambiguous', $message);
        self::assertStringContainsString('2 sessions match', $message);
        self::assertStringContainsString('deadbeef', $message);
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * @param list<string> $args
     *
     * @return array{0:int,1:string}
     */
    private function runUsage(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['candy-code', ...$args]));

        return [$rc, (string) ob_get_clean()];
    }

    /**
     * @param list<string> $args
     */
    private function refusedUsageDoor(array $args): string
    {
        [$rc, $stdout] = $this->runUsage([...$args, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$stdout}");
        self::assertSame('usage', $decoded['error']['type'] ?? null, $stdout);

        return (string) ($decoded['error']['message'] ?? '');
    }
}
