<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Context\PromptFence;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Role;

/**
 * The "Active subagents" row a turn's dispatch carries while background work
 * is still running (roadmap 4.3-2): which background sessions this host owns,
 * what each was asked, and that their results arrive on their own.
 *
 * WHY IT EXISTS. A background `Task` returns `{agent_id}` at once, so a later
 * turn — after a compaction, a long detour, or a resumed session — has no
 * other way to know that work is still out. Without it a model asked "is the
 * audit done?" either guesses or starts the work a second time. Claude Code,
 * OpenClaw and Goose all keep the equivalent list in front of the model.
 *
 * A HIDDEN USER ROW AT THE TAIL, appended only when its bytes changed since
 * the copy the history already holds, and persisted into the history like the
 * todo reminder ({@see \SugarCraft\Crush\Todo\TodoReminder}) — so the next
 * request's prefix is this request, and an unchanged list is never re-sent.
 * The rows name no runtime, deliberately: a figure that moves every turn would
 * make the row change every turn.
 *
 * TRUST. The task text is the model's own, but it is quoted back as data:
 * {@see PromptFence::escape()} plus this row's own fence neutralised, so a
 * task that spells `</active-subagents>` cannot close the row early.
 */
final class ActiveSubagentsBlock
{
    /** The opening fence; {@see isBlock()} recognises a row by it. */
    public const FENCE = '<active-subagents>';

    /** The row's first line: who speaks, and what to do (and not do) with it. */
    public const PREAMBLE = 'Harness-supplied list of the background sub-agents and sessions still running for this '
        . 'conversation; it supersedes any earlier copy. Each one\'s status, output and stats arrive as a new message '
        . 'when it finishes: do not sleep, poll or re-run them to find out.';

    /** Byte ceiling on each task snippet the row quotes. */
    public const TASK_SNIPPET_BYTES = 160;

    private function __construct()
    {
    }

    /**
     * The row's bytes for $sessions, or '' when there is none.
     *
     * @param list<array{id: string, name: string, agent: string, task: string, createdAt: int, background: bool}> $sessions
     *        as {@see BackgroundSupervisor::ownedActiveSummaries()} reports them
     */
    public static function render(array $sessions): string
    {
        if ($sessions === []) {
            return '';
        }

        $lines = [self::FENCE, self::PREAMBLE, ''];
        foreach ($sessions as $session) {
            $who = $session['agent'] !== ''
                ? 'agent "' . $session['agent'] . '"'
                : 'background session';
            $lines[] = sprintf(
                '- %s (%s): %s',
                $session['id'],
                self::escape($who),
                self::escape(self::snippet($session['task'])),
            );
        }
        $lines[] = '</active-subagents>';

        return implode("\n", $lines);
    }

    /**
     * The row to append — user-role, hidden from the transcript, sent to the
     * model — or null when $sessions is empty.
     *
     * @param list<array{id: string, name: string, agent: string, task: string, createdAt: int, background: bool}> $sessions
     */
    public static function row(array $sessions): ?Message
    {
        $text = self::render($sessions);

        return $text === '' ? null : Message::user($text)->withUserVisible(false);
    }

    /**
     * Whether $sessions should be shown before the next request over
     * $history: there is something running, and the newest copy the history
     * holds is not this exact list.
     *
     * @param list<array{id: string, name: string, agent: string, task: string, createdAt: int, background: bool}> $sessions
     * @param iterable<mixed> $history root {@see Message} rows or typed engine messages
     */
    public static function due(array $sessions, iterable $history): bool
    {
        $text = self::render($sessions);

        return $text !== '' && self::latestIn($history) !== $text;
    }

    /**
     * The bytes of the newest row $history carries, or null for none.
     *
     * @param iterable<mixed> $history
     */
    public static function latestIn(iterable $history): ?string
    {
        $latest = null;
        foreach ($history as $row) {
            if (self::isBlock($row)) {
                $latest = $row instanceof UserMessage ? $row->content() : $row->content;
            }
        }

        return $latest;
    }

    /** Whether $message is one of these rows — a host's stored row or the engine's typed one. */
    public static function isBlock(mixed $message): bool
    {
        if ($message instanceof UserMessage) {
            return str_starts_with($message->content(), self::FENCE . "\n");
        }

        return $message instanceof Message
            && $message->role === Role::User
            && str_starts_with($message->content, self::FENCE . "\n");
    }

    private static function snippet(string $task): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $task));
        if (strlen($flat) <= self::TASK_SNIPPET_BYTES) {
            return $flat;
        }

        return rtrim(mb_strcut($flat, 0, self::TASK_SNIPPET_BYTES - 3, 'UTF-8')) . '…';
    }

    /**
     * {@see PromptFence::escape()}, then the `<` of this row's own fence
     * rewritten to `&lt;` — the rule the turn-context row applies to its own.
     */
    private static function escape(string $payload): string
    {
        return (string) preg_replace('~<(?=/?active-subagents(?:[\s/>]|\z))~i', '&lt;', PromptFence::escape($payload));
    }
}
