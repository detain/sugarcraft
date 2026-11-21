<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

use React\Promise\PromiseInterface;

/**
 * One chat platform as the router sees it: where text goes, how it is
 * chunked, and how an approval ask is presented. The router owns routing,
 * ordering and approval binding; a face owns the platform's wire and widgets
 * (buttons, cards, reply placeholders). Bundles implement this through their
 * own face interface, which extends this one.
 */
interface PlatformFace
{
    /** The platform name, as it appears in a conversation key ("discord", "msteams"). */
    public function name(): string;

    /** The largest text a single outbound message may carry on this platform. */
    public function textChunkLimit(): int;

    /** The most lines a single outbound message may carry on this platform. */
    public function maxLinesPerMessage(): int;

    /**
     * Send one already-chunked piece of text to the conversation the reply
     * address names. The router chains these so chunks land in order.
     *
     * @param array<string,mixed> $ref
     * @return PromiseInterface<mixed>
     */
    public function deliver(array $ref, string $chunk): PromiseInterface;

    /**
     * Present an open approval ask. The nonce is the per-ask answer secret the
     * router later checks on a button or card answer.
     *
     * @param array<string,mixed> $ref
     * @param array<string,mixed> $ask
     */
    public function promptApproval(array $ref, string $sessionId, array $ask, string $nonce = ''): void;

    /** Replace an approval's presentation with its resolved line. */
    public function retireApproval(string $askId, string $line): void;

    /** Show the platform's typing indicator in the conversation the reply address names. */
    public function typing(array $ref): void;
}
