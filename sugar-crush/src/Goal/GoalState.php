<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Goal;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * The session's `/goal` (roadmap 3.D-3): the condition, which command set it,
 * how many follow-up rounds it has driven, and whether it is still live.
 *
 * KEPT IN THE TRANSCRIPT, as one marker row per change, rather than as a field
 * on the chat model. The goal belongs to the SESSION, and the transcript is the
 * one piece of session state every route already carries: a resumed session
 * resumes its goal, `/branch` copies it, `/rewind` steps it back with the turn
 * it was set in, and `/clear` drops it — none of which a separate field would
 * do without a line in each of those routes. The marker is
 * {@see Message::$uiOnly} (the model never reads it) and not
 * {@see Message::$userVisible} (the transcript never paints it); the rows the
 * user reads about the goal are the notices written beside it. Compaction
 * hides rows rather than deleting them, so a goal outlives a `/compact`.
 *
 * Only the app writes a marker — a hidden UI-only System row is nothing a
 * prompt, a command reply or a model answer can produce — so a marker is read
 * by its prefix with no risk of the user's text being taken for one.
 */
final class GoalState
{
    /** The marker row's content prefix; the JSON state follows it. */
    public const MARKER_PREFIX = 'sugarcrush-goal:';

    public const ACTIVE = 'active';
    public const MET = 'met';
    public const STOPPED = 'stopped';
    public const CLEARED = 'cleared';

    private function __construct(
        public readonly GoalMode $mode,
        public readonly string $condition,
        public readonly int $round,
        public readonly string $status,
    ) {
    }

    /** A goal just set: live, no follow-up sent yet. */
    public static function new(GoalMode $mode, string $condition): self
    {
        return new self($mode, trim($condition), 0, self::ACTIVE);
    }

    /** A copy that has sent $round follow-up prompts. */
    public function withRound(int $round): self
    {
        return $this->mutate(['round' => max(0, $round)]);
    }

    /** A copy that is no longer live: {@see MET}, {@see STOPPED} or {@see CLEARED}. */
    public function withStatus(string $status): self
    {
        if (!\in_array($status, [self::ACTIVE, self::MET, self::STOPPED, self::CLEARED], true)) {
            throw new \InvalidArgumentException("Unknown goal status '{$status}'.");
        }

        return $this->mutate(['status' => $status]);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Whether the follow-up budget is spent: no further round may be sent. */
    public function exhausted(): bool
    {
        return $this->round >= $this->mode->maxRounds();
    }

    /** The marker row recording this state. */
    public function toMessage(): Message
    {
        return Message::notice(self::MARKER_PREFIX . json_encode([
            'mode' => $this->mode->value,
            'condition' => $this->condition,
            'round' => $this->round,
            'status' => $this->status,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->withUserVisible(false);
    }

    /** Whether $message is a goal marker row. */
    public static function isMarker(Message $message): bool
    {
        return $message->role === Role::System
            && $message->uiOnly
            && !$message->userVisible
            && str_starts_with($message->content, self::MARKER_PREFIX);
    }

    /**
     * The goal $history last recorded, live or not, or null when no goal was
     * ever set in it (or its last marker is unreadable).
     *
     * @param array<int, Message> $history
     */
    public static function fromHistory(array $history): ?self
    {
        for ($i = \count($history) - 1; $i >= 0; $i--) {
            $message = $history[$i] ?? null;
            if ($message instanceof Message && self::isMarker($message)) {
                return self::fromMarker($message);
            }
        }

        return null;
    }

    /** The live goal in $history, or null when there is none. */
    public static function activeIn(array $history): ?self
    {
        $state = self::fromHistory($history);

        return $state !== null && $state->isActive() ? $state : null;
    }

    private static function fromMarker(Message $message): ?self
    {
        $data = json_decode(substr($message->content, \strlen(self::MARKER_PREFIX)), true);
        if (!\is_array($data)) {
            return null;
        }
        $mode = GoalMode::tryFrom((string) ($data['mode'] ?? ''));
        $condition = $data['condition'] ?? null;
        $status = $data['status'] ?? null;
        if ($mode === null || !\is_string($condition) || trim($condition) === ''
            || !\in_array($status, [self::ACTIVE, self::MET, self::STOPPED, self::CLEARED], true)) {
            return null;
        }

        return new self($mode, trim($condition), max(0, (int) ($data['round'] ?? 0)), $status);
    }

    /** @param array<string, mixed> $changes */
    private function mutate(array $changes): self
    {
        $state = array_merge(get_object_vars($this), $changes);

        return new self($state['mode'], $state['condition'], $state['round'], $state['status']);
    }
}
