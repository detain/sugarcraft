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
     * Determine whether foreground blocking compaction is needed.
     *
     * Returns true when context usage reaches or exceeds the foreground
     * blocking threshold (95% by default). At this threshold, new input
     * is blocked until space is freed by compaction.
     *
     * Mirrors charmbracelet/bubbletea ContextCompactor.shouldCompactForeground.
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @param int $tokenLimit Maximum tokens allowed in context window.
     */
    public function shouldCompactForeground(array $messages, int $tokenLimit): bool
    {
        if ($tokenLimit <= 0) {
            return false;
        }

        $tokenCount = $this->countTokens($messages);
        $threshold = (int) ($tokenLimit * $this->config->foregroundBlockingThreshold / 100);

        return $tokenCount >= $threshold;
    }

    /**
     * Apply skill-aware compaction as a separate pass from message-history compaction.
     *
     * Each carried-forward skill is capped at roughly 5,000 tokens of its own content,
     * and the combined budget across every skill still in context is capped at roughly
     * 25,000 tokens. Past that combined cap, the least-recently-invoked skill's
     * content is the first to be dropped.
     *
     * This runs as its own pass, separate from message-history compaction, so a handful
     * of large skills can't eat the entire compaction budget before any conversation
     * history is touched.
     *
     * Mirrors charmbracelet/bubbletea ContextCompactor.compactSkills.
     *
     * @param array<array{role:string,content:string,name?:string,lastInvokedAt?:int}> $messages
     * @return array<array{role:string,content:string,name?:string,lastInvokedAt?:int}> Messages with skills filtered
     */
    public function compactSkills(array $messages): array
    {
        // Extract skill messages (skills have a special role marker)
        $skills = [];
        $nonSkills = [];

        foreach ($messages as $msg) {
            if (isset($msg['role']) && $msg['role'] === 'skill') {
                $skills[] = [
                    'name' => $msg['name'] ?? '',
                    'content' => $msg['content'] ?? '',
                    'lastInvokedAt' => $msg['lastInvokedAt'] ?? 0,
                ];
            } else {
                $nonSkills[] = $msg;
            }
        }

        // Apply skill budget limits via filterSkills
        $filteredSkills = $this->filterSkills($skills);

        // Reconstruct messages with filtered skills
        $result = $nonSkills;
        foreach ($filteredSkills as $skill) {
            $result[] = [
                'role' => 'skill',
                'name' => $skill['name'],
                'content' => $skill['content'],
                'lastInvokedAt' => $skill['lastInvokedAt'],
            ];
        }

        return $result;
    }

    /**
     * Compact a message array through stages 1-5.
     *
     * Stage 1: Preserve the most recent N full user/assistant PAIRS (recentPreserveCount).
     * Stage 2: Condense older exchanges into single-line summaries capturing
     *          "what happened and any key decisions made."
     * Stage 3: Group consecutive identical exchanges (e.g., repeated grep searches).
     * Stage 4: Replace file contents with metadata summaries.
     * Stage 5: Remove navigation steps while preserving final destination.
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

        // Stage 0: strip tool-result system messages before pairing
        // (tool results are voluminous intermediate outputs; they are not
        // part of the conversational exchange that needs preserving)
        $messages = $this->removeToolResults($messages);

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

        // Stage 3: group similar consecutive exchanges
        $summarized = $this->groupSimilarExchanges($summarized);

        // Stage 4: compact file references into metadata
        $summarized = $this->compactFileReferences($summarized);

        // Stage 5: remove navigation steps
        $summarized = $this->removeNavigationSteps($summarized);

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
     * Stage 3: Group consecutive identical exchanges into a single entry with count prefix.
     *
     * Groups consecutive messages with identical content (e.g., repeated "file not found"
     * errors, repeated grep searches) into a single entry prefixed with a count like "[3x]".
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function groupSimilarExchanges(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $result = [];
        $currentContent = null;
        $currentRole = null;
        $count = 0;

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';

            if ($content === $currentContent && $role === $currentRole) {
                $count++;
            } else {
                if ($currentContent !== null) {
                    if ($count > 1) {
                        $result[] = [
                            'role' => $currentRole,
                            'content' => "[{$count}x] {$currentContent}",
                        ];
                    } else {
                        $result[] = [
                            'role' => $currentRole,
                            'content' => $currentContent,
                        ];
                    }
                }
                $currentContent = $content;
                $currentRole = $role;
                $count = 1;
            }
        }

        // Don't lose the last group
        if ($currentContent !== null) {
            if ($count > 1) {
                $result[] = [
                    'role' => $currentRole,
                    'content' => "[{$count}x] {$currentContent}",
                ];
            } else {
                $result[] = [
                    'role' => $currentRole,
                    'content' => $currentContent,
                ];
            }
        }

        return $result;
    }

    /**
     * Stage 4: Replace file read message content with metadata summary.
     *
     * Detects "file read" type messages by looking for common file extension
     * patterns in message content, then replaces the full content with a
     * metadata summary like "[file: path/to/file.php, N lines]".
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function compactFileReferences(array $messages): array
    {
        return array_map(function (array $msg): array {
            $content = $msg['content'] ?? '';

            if (!$this->isFileReadMessage($content)) {
                return $msg;
            }

            $lines = substr_count($content, "\n") + 1;
            $metadata = $this->extractFileMetadata($content);

            return [
                'role' => $msg['role'] ?? 'assistant',
                'content' => "[file: {$metadata}, {$lines} lines]",
            ];
        }, $messages);
    }

    /**
     * Detect if message content represents a file read operation.
     */
    private function isFileReadMessage(string $content): bool
    {
        // Match common file extension patterns that indicate file content
        // e.g., "<?php\n...class Foo..." or "<?php\ndeclare(strict_types=1);..."
        $phpPattern = '/<\?php\s*\n/s';
        if (preg_match($phpPattern, $content)) {
            return true;
        }

        // Match patterns like "path/to/file.php" or "file.php" appearing as a header
        // followed by substantial content (file content display)
        if (preg_match('/^[\w\-\.\/]+\.(php|ts|js|tsx|jsx|json|html|txt|md|css|yaml|yml)\s*\n/s', $content)) {
            return true;
        }

        // Match content that starts with common file path patterns
        if (preg_match('/^\/[\w\-\.\/]+\.(php|ts|js|tsx|jsx|json|html|txt|md|css|yaml|yml)/m', $content)) {
            return true;
        }

        // Match content with multiple lines containing typical code patterns
        // (indentation, brackets, semicolons)
        if (preg_match('/^\s{2,}[\$\w]\S*\s*[;\{\}]/m', $content) && substr_count($content, "\n") > 3) {
            return true;
        }

        return false;
    }

    /**
     * Extract file path metadata from file read content.
     */
    private function extractFileMetadata(string $content): string
    {
        // Try to extract file path from the first line
        if (preg_match('/^([\w\-\.\/]+\.(php|ts|js|tsx|jsx|json|html|txt|md|css|yaml|yml))/', $content, $matches)) {
            return $matches[1];
        }

        // Try to find a path-like pattern anywhere in content
        if (preg_match('/([\w\-\.\/]+\.(php|ts|js|tsx|jsx|json|html|txt|md|css|yaml|yml))/', $content, $matches)) {
            return $matches[1];
        }

        // Fallback: return a generic indicator based on content characteristics
        $firstLine = explode("\n", $content)[0] ?? 'unknown';
        if (mb_strlen($firstLine) > 50) {
            return 'file';
        }

        return $firstLine;
    }

    /**
     * Stage 0: Remove tool result messages from older exchanges.
     *
     * Removes messages with role=system that carry tool_results, as these are
     * voluminous intermediate outputs that are summarized by stage 2 anyway.
     * Recent tool results (within recentPreserveCount pairs) are kept intact.
     *
     * @param array<array{role:string,content:string,?tool_results?:mixed}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function removeToolResults(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            fn(array $msg): bool => !(
                ($msg['role'] ?? '') === 'system'
                && isset($msg['tool_results'])
            )
        ));
    }

    /**
     * Stage 5: Remove navigation steps while preserving final destination or result.
     *
     * Removes messages whose content indicates navigation commands (e.g., "cd /path/to/dir",
     * "ls", "pwd") while preserving the final destination or result that follows.
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function removeNavigationSteps(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $result = [];
        $navPatterns = [
            '/^cd\s+/m',
            '/^ls\s*/m',
            '/^pwd$/m',
            '/^mkdir\s+/m',
            '/^rm\s+/m',
            '/^mv\s+/m',
            '/^cp\s+/m',
        ];

        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            $isNavigation = false;

            foreach ($navPatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $isNavigation = true;
                    break;
                }
            }

            if (!$isNavigation) {
                $result[] = $msg;
            }
        }

        return $result;
    }

    /**
     * Apply skill budget constraints to a list of active skills.
     *
     * Skills whose content exceeds the per-skill budget (skillBudgetPerSkill tokens)
     * are truncated. If the combined budget (skillBudgetCombined tokens) is exceeded,
     * the least-recently-invoked skills are dropped first.
     *
     * Mirrors charmbracelet/bubbletea ContextCompactor.filterSkills.
     *
     * @param array<array{name:string,content:string,lastInvokedAt:int}> $skills
     * @return array<array{name:string,content:string,lastInvokedAt:int}> Filtered skills
     */
    public function filterSkills(array $skills): array
    {
        if ($skills === []) {
            return [];
        }

        // Stage A: truncate each skill to per-skill budget
        $budgetPerSkill = $this->config->skillBudgetPerSkill;
        $maxCharsPerSkill = $budgetPerSkill * 4; // 1 token ≈ 4 chars

        $skills = array_map(function (array $skill) use ($maxCharsPerSkill): array {
            $content = $skill['content'] ?? '';
            if (mb_strlen($content) > $maxCharsPerSkill) {
                $skill['content'] = mb_substr($content, 0, $maxCharsPerSkill - 3) . '...';
            }
            return $skill;
        }, $skills);

        // Stage B: if combined budget exceeded, drop LRU skills until within limit
        $budgetCombined = $this->config->skillBudgetCombined;
        $maxCharsCombined = $budgetCombined * 4;

        $totalChars = array_sum(array_map(
            fn(array $s): int => mb_strlen($s['content'] ?? ''),
            $skills
        ));

        while ($totalChars > $maxCharsCombined && count($skills) > 1) {
            // Find least-recently-invoked (smallest lastInvokedAt)
            $lruIndex = 0;
            $lruTime = PHP_INT_MAX;
            foreach ($skills as $idx => $skill) {
                $invoked = $skill['lastInvokedAt'] ?? PHP_INT_MAX;
                if ($invoked < $lruTime) {
                    $lruTime = $invoked;
                    $lruIndex = $idx;
                }
            }

            // Remove the LRU skill
            $removedLen = mb_strlen($skills[$lruIndex]['content'] ?? '');
            array_splice($skills, $lruIndex, 1);
            $totalChars -= $removedLen;
        }

        return $skills;
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
