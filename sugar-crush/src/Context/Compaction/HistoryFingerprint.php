<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Compaction;

/**
 * What a model-written compaction summary was written ABOUT (roadmap 2.10):
 * the exchanges it condensed, in conversation order, and the prior summaries
 * it merged. Taken when the summarization request is built and again when the
 * result is about to be used, so a summary fetched ahead of need is spliced in
 * only while it still describes the history it would replace.
 *
 * WHY "STILL A PREFIX" AND NOT "EQUAL". The background summary is requested at
 * the 70% reminder tier and used at the 85% tier, and the history grows in
 * between: every turn sent pushes the oldest preserved exchange out of the
 * verbatim tail and into the condensed set. So the set a compaction condenses at
 * 85% is the set it would have condensed at 70% PLUS the exchanges that slid out
 * of the tail since — never equal, always extended at the END. A summary still
 * describes that history when (a) the exchanges it covers are the leading run of
 * the ones now condensed, in the same order, and (b) the prior summaries it
 * merged are the ones the history still carries. Each exchange key is a hash of
 * the exchange's own text ({@see \SugarCraft\Crush\Context\ContextCompactor::exchangeKey()}),
 * so a `/rewind`, a `/clear`, a session switch or a compaction that landed in
 * between all break (a) or (b), and the summary is discarded rather than spliced.
 * The exchanges past the covered run fall to the heuristic one-liner, which is
 * the same per-exchange fallback a model reply that omitted a record gets.
 */
final class HistoryFingerprint
{
    /**
     * @param list<string> $exchangeKeys
     */
    private function __construct(
        private readonly array $exchangeKeys,
        private readonly string $priorDigest,
    ) {
    }

    /**
     * The fingerprint of a compaction that would condense $exchanges (as
     * {@see \SugarCraft\Crush\Context\ContextCompactor::exchangesToSummarize()}
     * returns them) and merge $priorSummaries.
     *
     * @param list<array{key:string}> $exchanges
     * @param list<string> $priorSummaries
     */
    public static function of(array $exchanges, array $priorSummaries): self
    {
        return new self(
            array_values(array_map(static fn (array $e): string => (string) $e['key'], $exchanges)),
            hash('sha256', implode("\0", $priorSummaries)),
        );
    }

    /** @return list<string> the condensed exchanges' keys, oldest first */
    public function exchangeKeys(): array
    {
        return $this->exchangeKeys;
    }

    /** A digest of the prior summaries the compaction merges. */
    public function priorDigest(): string
    {
        return $this->priorDigest;
    }

    /** One stable digest of the whole fingerprint, for logs and equality. */
    public function digest(): string
    {
        return hash('sha256', $this->priorDigest . "\0" . implode("\0", $this->exchangeKeys));
    }

    /** True when there is no exchange to condense. */
    public function isEmpty(): bool
    {
        return $this->exchangeKeys === [];
    }

    /**
     * Whether a summary written for this fingerprint still describes a history
     * whose fingerprint is now $current: same prior summaries, and this one's
     * exchanges the leading run of $current's (see the class docblock). An empty
     * fingerprint matches nothing — it summarised nothing.
     */
    public function matches(self $current): bool
    {
        if ($this->exchangeKeys === [] || $this->priorDigest !== $current->priorDigest) {
            return false;
        }

        return \array_slice($current->exchangeKeys, 0, \count($this->exchangeKeys)) === $this->exchangeKeys;
    }
}
