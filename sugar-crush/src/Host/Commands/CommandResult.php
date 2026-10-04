<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Message;

/**
 * What one slash command produced (roadmap O-2h, Appendix O §4.2 and §6.3
 * `command.exec → CommandResult{rows[], effects[]}`): the transcript rows it
 * appends, in order, and the {@see CommandEffect}s its driver applies.
 *
 * The rows are already what the transcript keeps — the command's echo and its
 * reply are UI-only (the model never sees a `/permissions` report), a failure
 * is a `Role::System` notice, and the rare row the model MUST read (`/undo`'s
 * "the commit was reverted") is an ordinary one. A driver appends them as
 * they are.
 *
 * A command the headless host cannot run — the screen-only ones (`/theme`,
 * `/pane`, the pickers) and the ones whose logic has not left `Chat` yet — is
 * {@see self::clientOnly()}: no rows, and the JSON-RPC code
 * {@see self::CLIENT_ONLY} the wire answers with.
 */
final class CommandResult
{
    /** The `command.exec` error a screen-only command answers with over the wire. */
    public const CLIENT_ONLY = -32030;

    /**
     * @param list<Message> $rows
     * @param list<CommandEffect> $effects
     */
    private function __construct(
        public readonly array $rows,
        public readonly array $effects,
        public readonly bool $holdsTurn,
        public readonly ?int $errorCode,
        public readonly ?string $error,
    ) {
    }

    /** A result that appends $rows and changes nothing else. */
    public static function new(Message ...$rows): self
    {
        return new self(array_values($rows), [], false, null, null);
    }

    /**
     * The ordinary answer: $text echoed and $reply under it, both UI-only —
     * a local report the model must not be shown on its next turn.
     */
    public static function reply(string $text, string $reply): self
    {
        return self::new(Message::user($text)->withUiOnly(), Message::assistant($reply)->withUiOnly());
    }

    /** $text echoed and nothing else yet: the answer arrives with an effect. */
    public static function echo(string $text): self
    {
        return self::new(Message::user($text)->withUiOnly());
    }

    /**
     * A command that exited non-zero: the echo, then its output as a
     * `Role::System` notice — an app-generated failure is not a model reply
     * and must not be replayed to the provider as one. `$exitCode` is named
     * only when the command said nothing, so the one case where something
     * happened never reads as "nothing happened".
     */
    public static function failure(string $text, string $output, int $exitCode): self
    {
        $trimmed = trim($output);

        return self::new(
            Message::user($text)->withUiOnly(),
            Message::notice($trimmed !== ''
                ? $trimmed
                : sprintf('Command failed with exit code %d and produced no output.', $exitCode)),
        );
    }

    /** `/$name` runs only where there is a screen; nothing happened here. */
    public static function clientOnly(string $name): self
    {
        return new self([], [], false, self::CLIENT_ONLY, sprintf(
            '/%s runs in the TUI client only; nothing was run in this session.',
            $name,
        ));
    }

    /**
     * The command was not run, for $reason — a turn holds the session, say.
     * No rows and no wire code: the reason is the answer.
     */
    public static function refused(string $reason): self
    {
        return new self([], [], false, null, $reason);
    }

    /** A copy with $rows appended after this result's own. */
    public function withRows(Message ...$rows): self
    {
        return $this->mutate(['rows' => [...$this->rows, ...array_values($rows)]]);
    }

    public function withEffect(CommandEffect $effect): self
    {
        return $this->mutate(['effects' => [...$this->effects, $effect]]);
    }

    /**
     * A copy that leaves a running turn running: `/workflow pause|status`
     * typed while the run they control holds the session (audit WF-4). Every
     * other command runs idle, where releasing the turn changes nothing.
     */
    public function holdingTurn(): self
    {
        return $this->mutate(['holdsTurn' => true]);
    }

    /** Whether the command did not run ({@see self::refused()} or {@see self::clientOnly()}). */
    public function isRefused(): bool
    {
        return $this->error !== null;
    }

    /** Whether the command did not run here at all ({@see self::clientOnly()}). */
    public function isClientOnly(): bool
    {
        return $this->errorCode === self::CLIENT_ONLY;
    }

    /** The first effect of $kind, or null. */
    public function effect(CommandEffectKind $kind): ?CommandEffect
    {
        foreach ($this->effects as $effect) {
            if ($effect->kind === $kind) {
                return $effect;
            }
        }

        return null;
    }

    /**
     * The wire shape: the rows as `{role, content, uiOnly}` and the effect
     * kinds, or the error.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->error !== null) {
            return ['error' => array_filter(
                ['code' => $this->errorCode, 'message' => $this->error],
                static fn (mixed $value): bool => $value !== null,
            )];
        }

        return [
            'rows' => array_map(static fn (Message $row): array => [
                'role' => $row->role->value,
                'content' => $row->content,
                'uiOnly' => $row->uiOnly,
            ], $this->rows),
            'effects' => array_map(static fn (CommandEffect $effect): string => $effect->kind->value, $this->effects),
        ];
    }

    /** @param array<string, mixed> $changes */
    private function mutate(array $changes): self
    {
        return new self(...array_merge([
            'rows' => $this->rows,
            'effects' => $this->effects,
            'holdsTurn' => $this->holdsTurn,
            'errorCode' => $this->errorCode,
            'error' => $this->error,
        ], $changes));
    }
}
