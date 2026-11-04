<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use React\Http\Message\Response;
use SugarCraft\Code\Server\Http\Responses;
use SugarCraft\Code\Server\Http\Router;
use SugarCraft\Code\Server\Http\SharePageController;
use SugarCraft\Code\Server\Ws\Upgrade;
use SugarCraft\Code\Share\ShareStore;
use React\Http\Message\ServerRequest;

/**
 * The OD-10 render memo and its budgets (D-19..D-22, CW-SEC-02): the share
 * page answers a live link through a process-lifetime LRU keyed by
 * `share:local:<sessionId>:<revision>`, budgeted BEFORE anything is remembered,
 * and gated behind the ShareStore lookup on every single request. The pins
 * here are the ones the design names: one render per revision, a stale entry
 * never re-rendered while it is memoized, the truncation notice riding the
 * cached bytes, the ceilings themselves (count and envelope), and revocation
 * outranking all of it. SharePageTest owns the wire semantics; this file
 * owns the budget arithmetic and the memo's shape.
 */
#[CoversClass(SharePageController::class)]
final class ShareBudgetCacheTest extends \PHPUnit\Framework\TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = \tempnam(\sys_get_temp_dir(), 'g4d-budget-') . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
    }

    public function test_budget_rows_keeps_the_newest_window_and_reports_the_drop(): void
    {
        $rows = \array_fill(0, 601, 'row');
        $dropped = 0;
        $kept = SharePageController::budgetRows($rows, $dropped);
        self::assertCount(SharePageController::MAX_SHARE_ROWS, $kept, 'the window is the ISS-058 page size');
        self::assertSame(101, $dropped, 'six hundred and one rows shed their oldest hundred and one');
        self::assertSame('row', $kept[array_key_first($kept)]);

        $exact = \array_fill(0, SharePageController::MAX_SHARE_ROWS, 'row');
        $dropped = -1;
        self::assertSame($exact, SharePageController::budgetRows($exact, $dropped), 'at the ceiling nothing moves');
        self::assertSame(0, $dropped, 'and nothing is reported shed');
    }

    public function test_the_notice_names_the_shed_rows_and_survives_the_cached_bytes(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-notice', 'html', '<p>FALLBACK</p>', 7, live: true);

        // Production-shape reply: the renderer did the row slice and stitched
        // the fixed notice into the artifact BEFORE the controller saw it —
        // the memo must store exactly these bytes, notice included (D-19's
        // budget-then-cache law; the cache-then-budget flip would remember
        // pre-notice bytes and serve different content than it caches).
        $body = '<p>page</p>' . SharePageController::truncationNotice(101);
        $controller = new SharePageController($store, static fn(string $id): ?array => ['html' => $body, 'revision' => 1]);
        $served = $this->route($controller, $record->path());
        self::assertSame(200, $served->getStatusCode());
        self::assertStringContainsString('first 101 rows are not shared', (string) $served->getBody());

        self::assertSame([(string) $served->getBody()], \array_values($this->memo($controller)), 'the memo holds the served post-notice bytes verbatim (budget-then-cache)');
    }

    public function test_two_requests_at_one_revision_render_exactly_once(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-one', 'html', '<p>FALLBACK</p>', 7, live: true);

        $renders = 0;
        $revision = 1;
        $renderer = function (string $id) use (&$renders, &$revision): ?array {
            ++$renders;
            return ['html' => '<p>r' . $revision . '</p>', 'revision' => $revision];
        };
        $probe = function (string $id) use (&$revision): ?int {
            return $revision;
        };
        $controller = new SharePageController($store, $renderer, $probe);

        self::assertStringContainsString('<p>r1</p>', (string) $this->route($controller, $record->path())->getBody());
        $second = $this->route($controller, $record->path());
        self::assertStringContainsString('<p>r1</p>', (string) $second->getBody());
        self::assertSame(1, $renders, 'the second GET at the same revision is a memo hit, not a render (D-20)');
        self::assertSame('no-store', $second->getHeaderLine('Cache-Control'), 'the memo does not soften the client law (D-22)');
    }

    public function test_the_memo_key_carries_the_revision_not_the_wall_clock(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-key', 'html', '<p>FALLBACK</p>', 7, live: true);

        $controller = new SharePageController($store, static fn(string $id): ?array => ['html' => '<p>one</p>', 'revision' => 1]);
        $this->route($controller, $record->path());
        self::assertSame(['share:local:sess-key:1'], \array_keys($this->memo($controller)), 'the key grammar is share:local:<sessionId>:<revision> (D-20)');
    }

    public function test_a_superseded_revision_is_served_from_the_memo_not_re_rendered(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-echo', 'html', '<p>FALLBACK</p>', 7, live: true);

        $renders = 0;
        $wanted = 1;
        // The probe answers whatever revision the test is asking about, so a
        // revisit to an older-but-still-memoized revision must come back from
        // the memo: a time-keyed (or single-slot) memo would re-render here.
        $probe = function (string $id) use (&$wanted): ?int {
            return $wanted;
        };
        $renderer = function (string $id) use (&$renders, &$wanted): ?array {
            ++$renders;
            return ['html' => '<p>rev-' . $wanted . '</p>', 'revision' => $wanted];
        };
        $controller = new SharePageController($store, $renderer, $probe);

        self::assertStringContainsString('<p>rev-1</p>', (string) $this->route($controller, $record->path())->getBody());
        $wanted = 2;
        self::assertStringContainsString('<p>rev-2</p>', (string) $this->route($controller, $record->path())->getBody());
        $wanted = 1;
        $back = $this->route($controller, $record->path());
        self::assertStringContainsString('<p>rev-1</p>', (string) $back->getBody(), 'the older entry is still memoized and correct');
        self::assertSame(2, $renders, 'naming rev 1 again found it in the memo, it did not re-render (D-20)');
    }

    public function test_the_byte_budget_clamps_the_reply_at_insert(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-big', 'html', '<p>FALLBACK</p>', 7, live: true);

        $oversize = '<p>' . \str_repeat('x', SharePageController::MAX_SHARE_BYTES + 65_536) . '</p>';
        $controller = new SharePageController($store, static fn(string $id): ?array => ['html' => $oversize, 'revision' => 1]);
        $served = $this->route($controller, $record->path());
        self::assertSame(200, $served->getStatusCode());
        $body = (string) $served->getBody();
        self::assertLessThanOrEqual(SharePageController::MAX_SHARE_BYTES, \strlen($body), 'the page never exceeds the 512 KiB envelope (D-19)');
        self::assertStringContainsString('reached its byte budget', $body, 'and says so, fixed prose');

        $memo = $this->memo($controller);
        $stored = (string) \reset($memo);
        self::assertSame($body, $stored, 'what was remembered is exactly what was served — the clamp rides INTO the memo, never after it');
        self::assertLessThanOrEqual(SharePageController::MAX_SHARE_BYTES, \strlen($stored));
    }

    public function test_the_entry_count_ceiling_evicts_the_least_used_revision(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-lru', 'html', '<p>FALLBACK</p>', 7, live: true);

        $renders = 0;
        $wanted = 0;
        $probe = function (string $id) use (&$wanted): ?int {
            return $wanted;
        };
        $renderer = function (string $id) use (&$renders, &$wanted): ?array {
            ++$renders;
            return ['html' => '<p>r' . $wanted . '</p>', 'revision' => $wanted];
        };
        $controller = new SharePageController($store, $renderer, $probe);

        $total = SharePageController::MAX_SHARE_CACHE_ENTRIES + 2;
        for ($i = 1; $i <= $total; ++$i) {
            $wanted = $i;
            $this->route($controller, $record->path());
        }
        $memo = $this->memo($controller);
        self::assertSame(SharePageController::MAX_SHARE_CACHE_ENTRIES, \count($memo), 'sixteen entries is the ceiling (D-21)');
        $expected = [];
        for ($r = $total - SharePageController::MAX_SHARE_CACHE_ENTRIES + 1; $r <= $total; ++$r) {
            $expected[] = 'share:local:sess-lru:' . $r;
        }
        self::assertSame($expected, \array_keys($memo), 'the newest sixteen live in insertion-use order; the two oldest decayed out');

        // Revisiting a decicked revision must re-render it — the bound dropped
        // the entry, it did not merely hide it.
        $wanted = 1;
        $before = $renders;
        $this->route($controller, $record->path());
        self::assertSame($before + 1, $renders, 'revision 1 is gone from the memo, so it renders again');
        self::assertStringContainsString('<p>r1</p>', (string) $this->route($controller, $record->path())->getBody());
        self::assertSame($before + 1, $renders, 'and the second look at it is a memo hit this time');
    }

    public function test_the_envelope_ceiling_evicts_until_the_sum_fits(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-env', 'html', '<p>FALLBACK</p>', 7, live: true);

        // Ten separate controllers each memoize one oversized page...
        $fill = \str_repeat('y', SharePageController::MAX_SHARE_BYTES - 1024);
        $bytes = 0;
        // ...but the envelope is per-process-per-controller, so the honest
        // exercise is one controller walking revisions whose pages each fit
        // the row budget but not the shared envelope.
        $renderer = static function (string $id): ?array {
            static $rev = 0;
            ++$rev;
            return ['html' => '<p>' . \str_repeat('y', 500_000) . '</p>', 'revision' => $rev];
        };
        $controller = new SharePageController($store, $renderer);
        for ($i = 0; $i < 9; ++$i) {
            $this->route($controller, $record->path());
        }
        $memo = $this->memo($controller);
        foreach ($memo as $entry) {
            $bytes += \strlen((string) $entry);
        }
        self::assertLessThanOrEqual(SharePageController::MAX_SHARE_CACHE_BYTES, $bytes, 'the memo never exceeds the 4 MiB envelope (D-21)');
        self::assertLessThan(9, \count($memo), 'nine 500 KiB pages cannot all live under 4 MiB — evictions happened');
    }

    public function test_revocation_outranks_a_warm_memo(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-revoked', 'html', '<p>FALLBACK</p>', 7, live: true);

        $renders = 0;
        $renderer = function (string $id) use (&$renders): ?array {
            ++$renders;
            return ['html' => '<p>live-' . $renders . '</p>', 'revision' => $renders];
        };
        $probe = static fn(string $id): ?int => 1;
        $controller = new SharePageController($store, $renderer, $probe);

        self::assertSame(200, $this->route($controller, $record->path())->getStatusCode(), 'first request renders');
        $store->revoke($record->token);
        $gone = $this->route($controller, $record->path());
        self::assertSame(404, $gone->getStatusCode(), 'the revoked row answers the shared death even with warm memo bytes (D-20)');
        self::assertStringContainsString('does not exist, or it has expired or been revoked', (string) $gone->getBody());
    }

    public function test_a_malformed_renderer_reply_answers_the_shared_death(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-malformed', 'html', '<p>FALLBACK</p>', 7, live: true);

        $stringReply = new SharePageController($store, static fn(string $id): mixed => '<p>legacy ?string</p>');
        self::assertSame(404, $this->route($stringReply, $record->path())->getStatusCode(), 'a pre-D-20 string reply is refused, not rendered');

        $missingRevision = new SharePageController($store, static fn(string $id): ?array => ['html' => '<p>x</p>']);
        self::assertSame(404, $this->route($missingRevision, $record->path())->getStatusCode(), 'html without a revision cannot key a memo — refused');
    }

    public function test_cap_bytes_leaves_under_budget_pages_byte_identical(): void
    {
        $small = '<p>plain</p>';
        self::assertSame($small, SharePageController::capBytes($small), 'under budget is not touched');
        self::assertSame($small, SharePageController::capBytes(SharePageController::capBytes($small)), 'idempotent');
    }

    public function test_cap_bytes_ends_outside_a_partial_tag_and_a_partial_sequence(): void
    {
        // Build a page whose cut would land mid-attribute and mid-codepoint:
        // 512 KiB of valid <p> filler, then one long tag and a multibyte run.
        $pad = \str_repeat('<p>' . \str_repeat('a', 250) . '</p>', (int) \ceil(SharePageController::MAX_SHARE_BYTES / 256));
        $dirty = $pad . '<img src="' . \str_repeat('b', 4096) . '" alt="x"' . "\u{4e00}\u{4e8c}\u{c2}\u{a0}" . '</p>';
        $capped = SharePageController::capBytes($dirty);

        self::assertLessThanOrEqual(SharePageController::MAX_SHARE_BYTES, \strlen($capped));
        self::assertStringEndsWith('</p>', $capped, 'the artifact always closes');
        self::assertSame(1, \preg_match('//u', $capped), 'the capped bytes are well-formed UTF-8');
        $tail = \substr($capped, -64, -\strlen('</p>'));
        self::assertStringNotContainsString('<', $tail, 'no unterminated tag survives the cut region');
        // Idempotent: the notice tail is inside the budget, so a second cap is a pass-through.
        self::assertSame($capped, SharePageController::capBytes($capped));
    }

    public function test_cap_bytes_reports_the_row_drop_alongside_the_row_notice_shape(): void
    {
        // The two fixed notices are distinct artifacts with distinct wording;
        // a rename that merges them would let a row-truncated page masquerade
        // as byte-truncated (or the reverse). Pin both texts verbatim.
        self::assertSame('<p>… first 7 rows are not shared on this page; the owner can publish a fresh snapshot from the session view.</p>', SharePageController::truncationNotice(7));
        $dirty = \str_repeat('<p>' . \str_repeat('c', 250) . '</p>', (int) \ceil(SharePageController::MAX_SHARE_BYTES / 250));
        self::assertStringContainsString('reached its byte budget', SharePageController::capBytes($dirty));
    }

    // ------------------------------------------------------------------

    private function route(SharePageController $share, string $path): Response
    {
        $router = new Router(
            static fn(): Response => Responses::error(500, 'no', 'upgrade must not run'),
            static fn(): Response => Responses::error(500, 'no', 'api must not run'),
            static fn(): Response => Responses::error(500, 'no', 'static must not run'),
            $share(...),
        );
        $response = $router(new ServerRequest('GET', 'http://127.0.0.1:7420' . $path));
        self::assertInstanceOf(Response::class, $response);
        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function memo(SharePageController $controller): array
    {
        $property = new \ReflectionProperty(SharePageController::class, 'rendered');
        $value = $property->getValue($controller);
        self::assertIsArray($value);
        /** @var array<string, string> $value */
        return $value;
    }
}
