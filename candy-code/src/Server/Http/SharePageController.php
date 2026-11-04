<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use SugarCraft\Code\Share\ShareStore;

/**
 * GET `/s/{token}` — one published session page, served PUBLIC (SES-3,
 * COMPARISON §6.5).
 *
 * PUBLIC ON PURPOSE: the token is the capability, and `AuthMiddleware`
 * protects only `/ws` and `/api/*`, so the Router reaches this handler with
 * no cookie and no ticket. That is safe exactly as long as the token is the
 * only secret — {@see ShareStore} enforces expiry and revocation per
 * request, and unknown, expired and revoked answer the identical
 * byte-for-byte 404, so the status line cannot enumerate tokens.
 *
 * A SNAPSHOT row is rendered from the text captured at its publish act
 * (snapshot-at-explicit-user-act is the security model): later edits to the
 * session cannot reach a page that is already public.
 *
 * A LIVE row (C-SES-3.d, OD-10) is re-rendered from the CURRENT session
 * transcript through the optional $liveRenderer —
 * `Closure(string $sessionId): ?array{html: string, revision: int}`, given
 * the row's session id, answering the page's HTML plus the session's lastSeq
 * at render, or null when the session is gone (which answers the same shared
 * 404, never a different one). The renderer is wired only by the serve
 * process ({@see \SugarCraft\Code\Cli\Serve}); a live row on a controller
 * without one falls back to its stored publish-time snapshot, the law the row
 * was published under. The renderer re-serializes through the same
 * ShareSession path a snapshot took, so the row filter and the secret
 * redaction are identical — the difference is WHEN it runs, not HOW.
 *
 * OD-10 sizes the render before it is ever kept: the live render is
 * truncated to {@see MAX_SHARE_ROWS} newest rows and {@see MAX_SHARE_BYTES},
 * with a fixed notice row inside the HTML saying what was left out (D-19,
 * mirroring the ISS-058 `SessionSnapshot::MAX_PAGE_ROWS` precedent —
 * budget-then-cache: the cached artifact is the post-budget bytes, never the
 * full transcript). The controller then MEMOIZES the render per
 * session revision under key grammar `share:local:<sessionId>:<revision>`
 * (D-30 §2.5), probed through the optional `liveRevision` peek — a cheap
 * lastSeq read whose hit lets an unchanged revision skip the re-serialization
 * entirely, which is the whole point of the memo (D-20's one-render law; a
 * renderer's own reported revision stays the name of record for what it
 * caches). A new transcript row bumps lastSeq, the next request misses,
 * re-renders, and the stale entry decays under the LRU bound (D-21). The
 * memo is a server-process render cache, not an HTTP cache —
 * `ShareStore::lookup` still gates EVERY request ahead of any memo touch, so
 * revocation lands on the next request regardless of what is memoized.
 * Snapshot rows are never memoized: the DB read is the final artifact.
 *
 * `Cache-Control: no-store` despite any of that (D-22): revocation must land
 * on the NEXT request, and a cache (proxy or browser) holding the page past
 * a revoke would serve a transcript the user has just unpublished. The
 * header is the client's law; D-20 is the process's own scratchpad. For live
 * rows the header turns load-bearing: a cached copy OUTSIDE this process
 * would freeze a page that promises to track its session.
 */
final class SharePageController
{
    /**
     * Newest transcript rows a live share page renders (ISS-058 precedent:
     * mirrors {@see \SugarCraft\Code\Host\SessionSnapshot::MAX_PAGE_ROWS}).
     */
    public const MAX_SHARE_ROWS = 500;

    /** Byte budget for one rendered share page — 512 KiB of HTML. */
    public const MAX_SHARE_BYTES = 524_288;

    /** Memo entries per serve process (D-21). */
    public const MAX_SHARE_CACHE_ENTRIES = 16;

    /**
     * Memo envelope per serve process — 4 MiB, the same envelope as
     * `Dispatcher::MAX_SERVER_FRAME_BYTES`: sized so the full memo is never
     * larger than one max-size wire frame.
     */
    public const MAX_SHARE_CACHE_BYTES = 4_194_304;

    /** @var array<string, string> session-revision key => post-budget HTML, oldest-use-first (LRU) */
    private array $rendered = [];

    public function __construct(
        private readonly ShareStore $shares,
        private readonly ?Closure $liveRenderer = null,
        private readonly ?Closure $liveRevision = null,
    ) {
    }

    /**
     * The newest-rows slice a live render may take (D-19): rows past
     * {@see MAX_SHARE_ROWS} drop from the FRONT — a share page shows the end
     * of a conversation — and the out-param names how many fell off so the
     * caller can fold {@see truncationNotice()} into the HTML. Rows ride as
     * opaque store arrays because the budget belongs to the page size, not
     * to any message shape.
     *
     * @param list<mixed> $rows
     * @param-out int $dropped
     *
     * @return list<mixed>
     */
    public static function budgetRows(array $rows, int &$dropped = 0): array
    {
        $over = \count($rows) - self::MAX_SHARE_ROWS;
        if ($over <= 0) {
            $dropped = 0;

            return $rows;
        }
        $dropped = $over;

        return \array_slice($rows, -self::MAX_SHARE_ROWS);
    }

    /**
     * The fixed notice row a truncated live render carries INSIDE its HTML —
     * part of the cached artifact, not bolted on at serve time, so a memo
     * hit cannot serve bytes the budget never approved (D-19).
     */
    public static function truncationNotice(int $droppedRows): string
    {
        return '<p>… first ' . $droppedRows . ' rows are not shared on this page; '
            . 'the owner can publish a fresh snapshot from the session view.</p>';
    }

    /**
     * Apply the byte budget to a rendered page: truncate to
     * {@see MAX_SHARE_BYTES} (cut landing outside any partial tag or
     * multibyte sequence) with the budget notice as the tail. Idempotent —
     * in-budget bytes pass through untouched.
     */
    public static function capBytes(string $html): string
    {
        if (strlen($html) <= self::MAX_SHARE_BYTES) {
            return $html;
        }
        $tail = '<p>… this page reached its byte budget; the owner can publish a fresh snapshot from the session view.</p>';
        $cut = substr($html, 0, self::MAX_SHARE_BYTES - strlen($tail));
        // Never end inside a tag: a '<' with no '>' after it opened a tag the
        // cut truncated mid-attribute — drop it whole.
        $lt = strrpos($cut, '<');
        if ($lt !== false && strpos($cut, '>', $lt) === false) {
            $cut = substr($cut, 0, $lt);
        }
        // Never end inside a UTF-8 sequence: strip trailing continuation
        // bytes, then the orphan lead byte (its tail was just stripped).
        $end = strlen($cut) - 1;
        while ($end >= 0 && (ord($cut[$end]) & 0xC0) === 0x80) {
            --$end;
        }
        if ($end >= 0 && ord($cut[$end]) >= 0xC0) {
            --$end;
        }

        return substr($cut, 0, $end + 1) . $tail;
    }

    /**
     * Answer the request, or null for anything that is not a share-page
     * GET/HEAD — the Router treats null as "fall through to the static UI".
     */
    public function __invoke(ServerRequestInterface $request): ?ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/' . ShareStore::URL_PREFIX . '/')) {
            return null;
        }
        $method = $request->getMethod();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return Responses::error(405, 'method_not_allowed', 'share pages are served by GET only', ['Allow' => 'GET, HEAD']);
        }

        $token = substr($path, strlen('/' . ShareStore::URL_PREFIX . '/'));
        if ($token === '' || str_contains($token, '/')) {
            return $this->notFound();
        }

        $record = $this->shares->lookup($token);
        if ($record === null) {
            return $this->notFound();
        }

        $html = $record->html;
        if ($record->live && $this->liveRenderer !== null) {
            // D-20's one-render law: the cheap probe names a revision, the memo
            // is consulted with it, and an unchanged revision serves WITHOUT
            // invoking the renderer — the probe only widens what may be
            // skipped. The lookup above already gated this request; nothing
            // between here and the response re-consults it, and nothing needs
            // to — revoke retires the row, the memo entry loses its door, and
            // decays unused.
            $probed = $this->liveRevision === null ? null : ($this->liveRevision)($record->sessionId);
            $key = \is_int($probed)
                ? \sprintf('share:local:%s:%d', $record->sessionId, $probed)
                : null;
            if ($key !== null && isset($this->rendered[$key])) {
                $html = $this->rendered[$key];
                unset($this->rendered[$key]);
                $this->rendered[$key] = $html; // LRU touch: most recent use moves last.
            } else {
                $rendered = ($this->liveRenderer)($record->sessionId);
                if (!\is_array($rendered) || !\is_string($rendered['html'] ?? null) || !\is_int($rendered['revision'] ?? null)) {
                    // The session behind a live page is gone: the same death the
                    // token itself would answer, indistinguishable from it. A
                    // malformed renderer reply dies the same way — the controller
                    // never serves what it cannot name a revision for.
                    return $this->notFound();
                }
                // Budget-then-cache (D-19): the bytes that enter the memo are
                // post-budget even if the renderer forgot its cap, so no
                // order of operations inside this class can cache an
                // un-budgeted artifact. The renderer's reported revision is
                // the name of record, probe or no probe.
                $html = self::capBytes($rendered['html']);
                $this->remember(\sprintf('share:local:%s:%d', $record->sessionId, $rendered['revision']), $html);
            }
        }

        return (new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], $html));
    }

    /**
     * Insert under the memo's LRU bound (D-21): newest entries evict oldest
     * first, on entry count or on total bytes, whichever bites sooner.
     */
    private function remember(string $key, string $html): void
    {
        $this->rendered[$key] = $html;
        while (\count($this->rendered) > self::MAX_SHARE_CACHE_ENTRIES) {
            unset($this->rendered[\array_key_first($this->rendered)]);
        }
        while (\count($this->rendered) > 1 && \array_sum(\array_map(\strlen(...), $this->rendered)) > self::MAX_SHARE_CACHE_BYTES) {
            unset($this->rendered[\array_key_first($this->rendered)]);
        }
    }

    private function notFound(): ResponseInterface
    {
        // One sentence for all three deaths — unknown, expired, revoked. The
        // grouping is policy, never per-token knowledge.
        return Responses::error(404, 'not_found', 'this share page does not exist, or it has expired or been revoked');
    }
}
