<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Bridge;

use React\Promise\PromiseInterface;

/**
 * Optional progressive-reply leg of a {@see PlatformFace}. A face that can
 * edit its own posts opts in here; the router probes for it and falls back
 * to plain delivery for every other face, so the base contract stays three
 * verbs and a typing nudge.
 *
 * The lifecycle per conversation: beginReply() when a prompt is admitted,
 * then exactly one of finishReply() (the final reply, chunked by the router)
 * or abandonReply() (the turn ended without one).
 */
interface StreamingFace
{
    /** Open a progressive placeholder for a prompt just admitted. No-op when streaming is off. */
    public function beginReply(array $ref): void;

    /** True while a placeholder opened by beginReply() is still waiting for its reply. */
    public function hasOpenReply(array $ref): bool;

    /**
     * Settle the open placeholder with the final chunks: the first chunk
     * replaces the placeholder in place, the rest post as new messages.
     *
     * @param list<string> $chunks already redacted and chunked by the router
     *
     * @return PromiseInterface<void>
     */
    public function finishReply(array $ref, array $chunks): PromiseInterface;

    /** Close a placeholder whose turn ended without a reply. */
    public function abandonReply(array $ref): void;
}
