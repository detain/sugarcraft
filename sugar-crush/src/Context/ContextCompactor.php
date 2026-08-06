<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * Handles automatic context compaction when conversation history grows large.
 *
 * Stage 1 preserves full messages for the most recent N exchanges
 * (default 10 from CompactorConfig::recentPreserveCount).
 *
 * Stage 2 condenses older exchanges into single-line summaries capturing
 * "what happened and any key decisions made."
 *
 * The compaction trigger uses tiered thresholds:
 * - 70%: reminder sent to lead agent
 * - 85%: background compaction begins
 * - 95%: foreground blocking until space is freed
 *
 * Token counting estimates 1 token ≈ 4 characters for PHP strings.
 */
final class ContextCompactor
{
    private int $lastSavingsPercentage = 0;

    public function __construct(
        private readonly CompactorConfig $config,
    ) {}

    /**
     * Factory creating a compactor with default config.
     */
    public static function new(): self
    {
        return new self(CompactorConfig::new());
    }

    /**
     * Determine whether compaction should run based on current token usage.
     *
     * Returns true when context usage reaches or exceeds the background
     * compaction threshold (85% by default). Uses token counting with
     * the estimate that 1 token ≈ 4 characters.
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @param int $tokenLimit Maximum tokens allowed in context window.
     */
    public function shouldCompact(array $messages, int $tokenLimit): bool
    {
        if ($tokenLimit <= 0) {
            return false;
        }

        $tokenCount = $this->countTokens($messages);
        $threshold = (int) ($tokenLimit * $this->config->backgroundCompactionThreshold / 100);

        return $tokenCount >= $threshold;
    }

    /**
     * Compact a message array through stages 1 and 2.
     *
     * Stage 1: Preserve the most recent N full user/assistant PAIRS (recentPreserveCount).
     * Stage 2: Condense older exchanges into single-line summaries capturing
     *          "what happened and any key decisions made."
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @return array<array{role:string,content:string}> Compacted messages.
     */
    public function compact(array $messages): array
    {
        if ($messages === []) {
            $this->lastSavingsPercentage = 0;
            return [];
        }

        $preserveCount = $this->config->recentPreserveCount;

        // Group messages into user/assistant pairs
        $pairs = $this->groupIntoPairs($messages);

        // Stage 1: if we have <= preserveCount pairs, no compaction needed
        if (count($pairs) <= $preserveCount) {
            $this->lastSavingsPercentage = 0;
            return $messages;
        }

        // Split: last preserveCount pairs are preserved, earlier pairs go to summary
        $preservePairs = array_slice($pairs, -$preserveCount);
        $toSummarizePairs = array_slice($pairs, 0, count($pairs) - $preserveCount);

        // Stage 2: condense older pairs into summaries (one summary per pair)
        $summarized = $this->summarizeExchanges($toSummarizePairs);

        // Flatten preserved pairs back into individual messages
        $preserved = [];
        foreach ($preservePairs as $pair) {
            $preserved[] = ['role' => 'user', 'content' => $pair['user']];
            if ($pair['assistant'] !== null) {
                $preserved[] = ['role' => 'assistant', 'content' => $pair['assistant']];
            }
        }

        $this->lastSavingsPercentage = $this->calculateSavingsPercentage($messages, [...$summarized, ...$preserved]);

        return [...$summarized, ...$preserved];
    }

    /**
     * Group a flat message array into user/assistant pairs.
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array<array{user:string,assistant:?string}> Pairs with user content and optional assistant content
     */
    private function groupIntoPairs(array $messages): array
    {
        $pairs = [];
        $currentPair = null;

        foreach ($messages as $msg) {
            $role = is_array($msg) ? ($msg['role'] ?? '') : '';
            $content = is_array($msg) ? ($msg['content'] ?? '') : (string) $msg;

            if ($role === 'user') {
                // Save previous pair if exists
                if ($currentPair !== null) {
                    $pairs[] = $currentPair;
                }
                $currentPair = ['user' => $content, 'assistant' => null];
            } elseif ($role === 'assistant' && $currentPair !== null) {
                $currentPair['assistant'] = $content;
            } else {
                // Other roles or assistant without a user pair - treat as standalone
                if ($currentPair !== null && $currentPair['assistant'] !== null) {
                    $pairs[] = $currentPair;
                    $currentPair = null;
                }
                if ($currentPair === null) {
                    $pairs[] = ['user' => '', 'assistant' => $content, 'standalone' => true, 'role' => $role];
                }
            }
        }

        // Don't lose the last pair
        if ($currentPair !== null) {
            $pairs[] = $currentPair;
        }

        return $pairs;
    }

    /**
     * Return the percentage of context space saved after the last compaction.
     *
     * @return int 0 if no compaction run, or percentage (0-100) of tokens saved.
     */
    public function savingsPercentage(): int
    {
        return $this->lastSavingsPercentage;
    }

    /**
     * Count estimated tokens in a message array.
     *
     * Uses the approximation 1 token ≈ 4 characters for PHP strings.
     * Each message also accounts for role overhead (~10 tokens).
     *
     * @param array<array{role:string,content:string}> $messages
     */
    private function countTokens(array $messages): int
    {
        $total = 0;
        foreach ($messages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? '') : (string) $msg;
            $total += (int) ceil(mb_strlen($content) / 4);
            $total += 10; // role overhead
        }
        return $total;
    }

    /**
     * Summarize older exchanges into single-line summaries.
     *
     * Takes an array of pairs (from groupIntoPairs) and produces one
     * summary message per pair, capturing "what happened and any key decisions made."
     *
     * @param array<array{user:string,assistant:?string,?standalone?:bool,?role?:string}> $pairs
     * @return array<array{role:string,content:string}>
     */
    private function summarizeExchanges(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        // Generate summaries - one per pair
        $summaries = [];
        foreach ($pairs as $pair) {
            if (isset($pair['standalone']) && $pair['standalone'] === true) {
                // Standalone message (unpaired role like 'system')
                $content = $pair['assistant'] ?? '';
                $role = $pair['role'] ?? 'assistant';
                // Truncate long standalone messages
                $summary = mb_strlen($content) > 120
                    ? mb_substr($content, 0, 117) . '...'
                    : $content;
                $summaries[] = [
                    'role' => $role,
                    'content' => '[summary] ' . $summary,
                ];
            } else {
                $userContent = $pair['user'] ?? '';
                $assistantContent = $pair['assistant'] ?? '';

                $summary = $this->generateExchangeSummary($userContent, $assistantContent);
                $summaries[] = [
                    'role' => 'assistant',
                    'content' => '[summary] ' . $summary,
                ];
            }
        }

        return $summaries;
    }

    /**
     * Generate a one-line summary for a user/assistant exchange.
     */
    private function generateExchangeSummary(string $userMsg, string $assistantMsg): string
    {
        // Extract the essence: what was asked and what was done
        $userMax = $this->config->summaryUserMaxChars;
        $userTruncated = mb_strlen($userMsg) > $userMax
            ? mb_substr($userMsg, 0, $userMax - 3) . '...'
            : $userMsg;

        // If assistant is short, include it directly
        if (mb_strlen($assistantMsg) <= $this->config->summaryAssistantMaxChars) {
            return $userTruncated . ' → ' . $assistantMsg;
        }

        // Otherwise just describe what happened
        return $userTruncated . ' → [exchanged information]';
    }

    /**
     * Calculate the percentage savings from compaction.
     *
     * @param array<array{role:string,content:string}> $original
     * @param array<array{role:string,content:string}> $compacted
     */
    private function calculateSavingsPercentage(array $original, array $compacted): int
    {
        $originalTokens = $this->countTokens($original);
        $compactedTokens = $this->countTokens($compacted);

        if ($originalTokens === 0) {
            return 0;
        }

        $savings = $originalTokens - $compactedTokens;
        $percentage = (int) (($savings / $originalTokens) * 100);

        return max(0, $percentage);
    }
}
