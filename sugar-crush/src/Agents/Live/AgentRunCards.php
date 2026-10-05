<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

/**
 * The record every delegated run keeps of itself so the messaging tools can
 * find it (roadmap 4.4): `SendMessage`, `Subagents` and `InterruptAgent`.
 *
 * WHY FILES. A delegated run lives in whatever process runs it — the turn
 * child, a parallel member's grandchild, a background daemon — and the agent
 * asking about it runs in yet another short-lived turn child, so no in-memory
 * registry can answer. Every run {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}
 * starts writes a small JSON card beside its mailbox,
 * `~/.sugar-crush/mailboxes/<session>/<run>/card.json` ({@see recordRun()}),
 * and settles it when it finishes ({@see updateRun()}): who it is, who
 * launched it, where its mailbox is, how it ended and the resume id that
 * continues it. The cards live in the {@see AgentInbox} tree, so they are kept
 * and swept with the session's mailboxes. `<session>` is the session the
 * run's seats count against: a background agent's mailbox is under its own
 * daemon session, its card under the session that started it, which is where
 * its parent looks.
 *
 * UNTRUSTED ON READ. A card is a file any process of the user can write, so
 * {@see runs()} shape-checks every field and skips a card whose run id does
 * not name its own directory. A forged card can at worst address a message —
 * unsigned, framed as an agent's, carrying no user authority — to another of
 * the user's own mailboxes.
 *
 * A run still marked running whose process is gone (a parallel member killed
 * with its group, a crashed daemon) reads as {@see STATUS_LOST}, never as
 * running forever.
 *
 * BEST EFFORT, like the transcript log: a card that cannot be written leaves
 * the run unaddressable, never failed.
 */
final class AgentRunCards
{
    /** The card's file name inside the run's mailbox directory. */
    public const CARD_FILE = 'card.json';

    /** The id the session's own agent goes by: its sub-agents' parent, and its mailbox's name. */
    public const MAIN = 'main';

    /** A card's status while its run works; afterwards it is the run's outcome. */
    public const STATUS_RUNNING = 'running';

    /** What a running card whose process is gone reads as. */
    public const STATUS_LOST = 'lost';

    /** At most this many followups a card keeps for its next run. */
    public const MAX_FOLLOWUPS = 20;

    /** Byte ceiling on each free-text field a card carries. */
    private const SNIPPET_BYTES = 200;

    private const ID_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    private function __construct()
    {
    }

    /**
     * Write run $card['runId']'s card under session $scope; false — never an
     * exception — when it could not be written.
     *
     * @param array<string, mixed> $card the fields {@see runs()} reads
     */
    public static function recordRun(string $scope, array $card, ?string $root = null): bool
    {
        $runId = \is_string($card['runId'] ?? null) ? $card['runId'] : '';
        $path = self::cardPath($scope, $runId, $root);
        if ($path === null) {
            return false;
        }

        return self::locked($path, static fn (): ?array => self::normalise($card + ['followups' => []])) !== null;
    }

    /**
     * Rewrite run $runId's card through $change, under the card's lock. Null
     * when there is no card (or it could not be read or written).
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     *
     * @return array<string, mixed>|null the card as written
     */
    public static function updateRun(string $scope, string $runId, \Closure $change, ?string $root = null): ?array
    {
        $path = self::cardPath($scope, $runId, $root);
        if ($path === null || !is_file($path)) {
            return null;
        }

        return self::locked($path, static function (?array $card) use ($change): ?array {
            return $card === null ? null : self::normalise($change($card));
        });
    }

    /**
     * Every readable card under session $scope, oldest first.
     *
     * @return list<array{runId: string, agent: string, description: string, task: string, parent: string,
     *                    inboxSession: string, background: string, status: string, resumeId: string,
     *                    error: string, startedAt: int, finishedAt: int, pid: int,
     *                    followups: list<array{from: string, text: string, ts: int}>}>
     */
    public static function runs(string $scope, ?string $root = null): array
    {
        $root ??= AgentInbox::defaultRoot();
        if ($root === null) {
            return [];
        }

        $cards = [];
        foreach (glob(rtrim($root, '/') . '/' . self::segment($scope) . '/*/' . self::CARD_FILE, GLOB_NOSORT) ?: [] as $file) {
            $card = self::normalise(json_decode((string) @file_get_contents($file), true));
            if ($card === null || self::segment($card['runId']) !== basename(\dirname($file))) {
                continue;
            }
            $cards[] = $card;
        }
        usort($cards, static fn (array $a, array $b): int => [$a['startedAt'], $a['runId']] <=> [$b['startedAt'], $b['runId']]);

        return $cards;
    }

    /**
     * The cards of the runs $parent launched, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function childrenOf(string $scope, string $parent, ?string $root = null): array
    {
        return array_values(array_filter(
            self::runs($scope, $root),
            static fn (array $card): bool => $card['parent'] === $parent,
        ));
    }

    /**
     * The newest card under $scope that $id names — by run id, background
     * `agent_id` or resume id — among those $parent launched; null for none.
     *
     * @return array<string, mixed>|null
     */
    public static function findRun(string $scope, string $id, string $parent, ?string $root = null): ?array
    {
        $found = null;
        foreach (self::childrenOf($scope, $parent, $root) as $card) {
            if (self::names($card, $id)) {
                $found = $card;
            }
        }

        return $found;
    }

    /**
     * Whether $id names $card's run: its run id, background `agent_id` or
     * resume id.
     *
     * @param array<string, mixed> $card
     */
    public static function names(array $card, string $id): bool
    {
        return $id !== '' && \in_array($id, [$card['runId'], $card['background'], $card['resumeId']], true);
    }

    /**
     * Take every followup kept for the conversation $resumeId continues,
     * clearing them, oldest first — what a resumed run reads first.
     *
     * @return list<array{from: string, text: string}>
     */
    public static function takeFollowups(string $scope, string $resumeId, ?string $root = null): array
    {
        $taken = [];
        foreach (self::runs($scope, $root) as $card) {
            if ($card['resumeId'] !== $resumeId || $card['followups'] === []) {
                continue;
            }
            self::updateRun($scope, $card['runId'], static function (array $fresh) use (&$taken): array {
                foreach ($fresh['followups'] as $followup) {
                    $taken[] = ['from' => $followup['from'], 'text' => $followup['text']];
                }
                $fresh['followups'] = [];

                return $fresh;
            }, $root);
        }

        return $taken;
    }

    /**
     * Whether $card's run is still working: marked running, and its process
     * (when the card names one and this build can ask) still exists.
     *
     * @param array<string, mixed> $card
     */
    public static function isLive(array $card): bool
    {
        if ($card['status'] !== self::STATUS_RUNNING) {
            return false;
        }
        $pid = $card['pid'];
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return true;
        }

        // EPERM: the process exists, it is only not ours to signal.
        return @posix_kill($pid, 0) || (\function_exists('posix_get_last_error') && posix_get_last_error() === 1);
    }

    /**
     * $card's status as the tools report it: its outcome, `running`, or
     * {@see STATUS_LOST}.
     *
     * @param array<string, mixed> $card
     */
    public static function statusOf(array $card): string
    {
        if ($card['status'] === self::STATUS_RUNNING) {
            return self::isLive($card) ? self::STATUS_RUNNING : self::STATUS_LOST;
        }

        return $card['status'];
    }

    /** One path segment, the rule {@see AgentInbox} names its directories by. */
    public static function segment(string $id): string
    {
        $segment = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $id);

        return $segment === '' || $segment === '.' || $segment === '..' ? '_' : $segment;
    }

    /**
     * A card as {@see runs()} trusts it, or null when it is not one.
     *
     * @return array{runId: string, agent: string, description: string, task: string, parent: string,
     *               inboxSession: string, background: string, status: string, resumeId: string,
     *               error: string, startedAt: int, finishedAt: int, pid: int,
     *               followups: list<array{from: string, text: string, ts: int}>}|null
     */
    private static function normalise(mixed $card): ?array
    {
        if (!\is_array($card)) {
            return null;
        }
        foreach (['runId', 'parent', 'inboxSession'] as $key) {
            if (!\is_string($card[$key] ?? null) || preg_match(self::ID_PATTERN, $card[$key]) !== 1) {
                return null;
            }
        }
        $text = static fn (mixed $value): string => \is_string($value) ? self::clip($value) : '';
        $background = $text($card['background'] ?? '');
        $resumeId = $text($card['resumeId'] ?? '');
        $status = $text($card['status'] ?? '');

        $followups = [];
        foreach (\is_array($card['followups'] ?? null) ? $card['followups'] : [] as $followup) {
            if (\is_array($followup) && \is_string($followup['from'] ?? null) && \is_string($followup['text'] ?? null)
                && trim($followup['text']) !== '' && \strlen($followup['text']) <= AgentMessage::MAX_TEXT_BYTES
            ) {
                $followups[] = ['from' => $followup['from'], 'text' => $followup['text'], 'ts' => \is_int($followup['ts'] ?? null) ? $followup['ts'] : 0];
            }
        }

        return [
            'runId' => $card['runId'],
            'agent' => $text($card['agent'] ?? ''),
            'description' => $text($card['description'] ?? ''),
            'task' => $text($card['task'] ?? ''),
            'parent' => $card['parent'],
            'inboxSession' => $card['inboxSession'],
            'background' => preg_match(self::ID_PATTERN, $background) === 1 ? $background : '',
            'status' => $status === '' ? self::STATUS_RUNNING : $status,
            'resumeId' => preg_match('/^[0-9a-f]{16}$/', $resumeId) === 1 ? $resumeId : '',
            'error' => $text($card['error'] ?? ''),
            'startedAt' => \is_int($card['startedAt'] ?? null) ? $card['startedAt'] : 0,
            'finishedAt' => \is_int($card['finishedAt'] ?? null) ? $card['finishedAt'] : 0,
            'pid' => \is_int($card['pid'] ?? null) ? $card['pid'] : 0,
            'followups' => \array_slice($followups, -self::MAX_FOLLOWUPS),
        ];
    }

    /**
     * $card's path, or null when there is no root or the run id names nothing.
     */
    private static function cardPath(string $scope, string $runId, ?string $root): ?string
    {
        $root ??= AgentInbox::defaultRoot();
        if ($root === null || preg_match(self::ID_PATTERN, $runId) !== 1) {
            return null;
        }

        return rtrim($root, '/') . '/' . self::segment($scope) . '/' . self::segment($runId) . '/' . self::CARD_FILE;
    }

    /**
     * Read-modify-write $path under an exclusive lock on its sibling `.lock`,
     * the new card renamed into place so a reader never sees half of one.
     *
     * @param \Closure(?array<string, mixed>): ?array<string, mixed> $change
     *
     * @return array<string, mixed>|null
     */
    private static function locked(string $path, \Closure $change): ?array
    {
        $previous = umask(0o077);
        try {
            $dir = \dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                return null;
            }
            if (is_link($dir) || is_link($path)) {
                return null;
            }
            $lock = @fopen($path . '.lock', 'c');
            if ($lock === false) {
                return null;
            }
            try {
                if (!flock($lock, LOCK_EX)) {
                    return null;
                }
                $current = is_file($path) ? self::normalise(json_decode((string) @file_get_contents($path), true)) : null;
                $next = $change($current);
                if ($next === null) {
                    return null;
                }
                $json = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $temp = @tempnam($dir, 'card-');
                if ($json === false || $temp === false) {
                    return null;
                }
                if (@file_put_contents($temp, $json) !== \strlen($json) || !@rename($temp, $path)) {
                    @unlink($temp);

                    return null;
                }

                return $next;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } finally {
            umask($previous);
        }
    }

    private static function clip(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return \strlen($text) > self::SNIPPET_BYTES ? rtrim(mb_strcut($text, 0, self::SNIPPET_BYTES, 'UTF-8')) . '…' : $text;
    }
}
