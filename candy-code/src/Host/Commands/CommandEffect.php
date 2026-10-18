<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\TitleSource;

/**
 * One session change a slash command asks for, beside the rows it appends
 * (roadmap O-2h, `CommandResult{rows[], effects[]}`).
 *
 * WHY EFFECTS AND NOT A MUTATED SESSION. A command runs for two drivers that
 * hold a session differently — `Chat` as an immutable model with a draft, a
 * scroll position and a Cmd channel; `SessionHost` as live mutable state on a
 * loop. A command that returned a new `Chat` could never run headless, and one
 * that mutated a host could never run in the TUI. So a command decides WHAT
 * changes and each driver applies it in its own terms
 * (`Chat::applyCommandResult()`, `SessionHost::applyCommandResult()`).
 *
 * The off-turn kinds carry a thunk rather than a promise: nothing starts until
 * the driver says so — `Chat` hands it to `Cmd::promise()`, a host calls it on
 * its loop — so building a result never runs the work.
 */
final class CommandEffect
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly CommandEffectKind $kind,
        private readonly array $data,
    ) {
    }

    public static function clearTranscript(): self
    {
        return new self(CommandEffectKind::ClearTranscript, []);
    }

    /**
     * @param list<Message> $messages the checkpoint's transcript
     * @param string $draft the draft the checkpoint captured
     * @param int|null $cursor its caret offset, when one was captured
     * @param array<string, string>|null $pasteChips the chip payloads stored
     *        beside the draft (ISS-051 review fix); null when the checkpoint
     *        carried none
     */
    public static function restoreCheckpoint(
        array $messages,
        string $draft,
        ?int $cursor,
        ?array $pasteChips = null,
        int $pasteCounter = 0,
    ): self {
        $data = [
            'messages' => array_values($messages),
            'draft' => $draft,
            'cursor' => $cursor,
        ];
        if ($pasteChips !== null) {
            $data['pasteChips'] = $pasteChips;
            $data['pasteCounter'] = $pasteCounter;
        }

        return new self(CommandEffectKind::RestoreCheckpoint, $data);
    }

    public static function switchSession(string $sessionId): self
    {
        return new self(CommandEffectKind::SwitchSession, ['sessionId' => $sessionId]);
    }

    public static function renameSession(?string $title, ?TitleSource $source): self
    {
        return new self(CommandEffectKind::RenameSession, ['title' => $title, 'source' => $source]);
    }

    public static function openTitleEditor(): self
    {
        return new self(CommandEffectKind::OpenTitleEditor, []);
    }

    /**
     * Open the media form modal (crush-media W3.1), optionally seeded with a
     * draft request; the TUI turns this into an OpenMediaFormMsg.
     */
    public static function openMediaForm(?\SugarCraft\Code\Media\MediaRequest $request = null): self
    {
        return new self(CommandEffectKind::OpenMediaForm, ['request' => $request]);
    }

    /** The draft seed carried by {@see self::openMediaForm()}, if any. */
    public function mediaRequest(): ?\SugarCraft\Code\Media\MediaRequest
    {
        $request = $this->data['request'] ?? null;

        return $request instanceof \SugarCraft\Code\Media\MediaRequest ? $request : null;
    }

    /**
     * Open the full-screen video player (crush-media W5.4) on a resolved
     * local path; the TUI turns this into an OpenVideoPlayerMsg.
     */
    public static function openVideoPlayer(string $path): self
    {
        return new self(CommandEffectKind::OpenVideoPlayer, ['path' => $path]);
    }

    /** The clip path carried by {@see self::openVideoPlayer()}. */
    public function videoPath(): string
    {
        $path = $this->data['path'] ?? null;

        return \is_string($path) ? $path : '';
    }

    /**
     * Off-turn work. $run starts it and resolves with whatever the TUI routes
     * (a `Msg`, or null for nothing to show); $describe turns that value into
     * the row a headless driver appends, or null for none. The row is a
     * `Message`, not a string, because its verdict travels with it (ISS-097):
     * a settled command that FAILED lands a flagged row, and the renderer
     * never sniffs text to find that out.
     *
     * @param \Closure(): PromiseInterface<mixed> $run
     * @param (\Closure(mixed): ?Message)|null $describe
     */
    public static function async(\Closure $run, ?\Closure $describe = null): self
    {
        return new self(CommandEffectKind::Async, ['run' => $run, 'describe' => $describe]);
    }

    /**
     * Work that occupies the session until $run resolves with its report —
     * the text of the UI-only row that settles it, or the row itself when it
     * carries a verdict (ISS-097: `/workflow` failures resolve, never reject,
     * so the error flag has to ride in the row). $cancellation is the
     * turn's own (Esc Esc, `session.cancel`), and $workflow marks the run
     * whose `/workflow pause|status` may still be typed while it holds the
     * session.
     *
     * @param \Closure(): PromiseInterface<string|Message> $run
     */
    public static function occupyTurn(\Closure $run, CancellationToken $cancellation, bool $workflow = true): self
    {
        return new self(CommandEffectKind::OccupyTurn, [
            'run' => $run,
            'cancellation' => $cancellation,
            'workflow' => $workflow,
        ]);
    }

    /** The session's "always" grants become $grants ({@see grants()}). */
    public static function setPermissionGrants(\SugarCraft\Code\Permissions\SessionPermissionMemo $grants): self
    {
        return new self(CommandEffectKind::SetPermissionGrants, ['grants' => $grants]);
    }

    /** The session's next turns run in $mode ({@see permissionMode()}). */
    public static function setPermissionMode(\SugarCraft\Code\Permissions\PermissionMode $mode): self
    {
        return new self(CommandEffectKind::SetPermissionMode, ['mode' => $mode]);
    }

    public function grants(): ?\SugarCraft\Code\Permissions\SessionPermissionMemo
    {
        $grants = $this->data['grants'] ?? null;

        return $grants instanceof \SugarCraft\Code\Permissions\SessionPermissionMemo ? $grants : null;
    }

    public function permissionMode(): ?\SugarCraft\Code\Permissions\PermissionMode
    {
        $mode = $this->data['mode'] ?? null;

        return $mode instanceof \SugarCraft\Code\Permissions\PermissionMode ? $mode : null;
    }

    /** @return list<Message> {@see self::restoreCheckpoint()}'s rows */
    public function messages(): array
    {
        return $this->data['messages'] ?? [];
    }

    public function draft(): string
    {
        return (string) ($this->data['draft'] ?? '');
    }

    public function cursor(): ?int
    {
        $cursor = $this->data['cursor'] ?? null;

        return \is_int($cursor) ? $cursor : null;
    }

    /** @return array<string, string> {@see self::restoreCheckpoint()}'s chip payloads (ISS-051) */
    public function pasteChips(): array
    {
        $chips = $this->data['pasteChips'] ?? [];

        return \is_array($chips) ? $chips : [];
    }

    public function pasteCounter(): int
    {
        $counter = $this->data['pasteCounter'] ?? 0;

        return \is_int($counter) ? $counter : 0;
    }

    public function sessionId(): string
    {
        return (string) ($this->data['sessionId'] ?? '');
    }

    public function title(): ?string
    {
        $title = $this->data['title'] ?? null;

        return \is_string($title) ? $title : null;
    }

    public function titleSource(): ?TitleSource
    {
        $source = $this->data['source'] ?? null;

        return $source instanceof TitleSource ? $source : null;
    }

    /** The thunk of an {@see self::async()} or {@see self::occupyTurn()} effect. */
    public function run(): \Closure
    {
        $run = $this->data['run'] ?? null;
        if (!$run instanceof \Closure) {
            throw new \LogicException("A {$this->kind->value} effect runs nothing.");
        }

        return $run;
    }

    /** @return (\Closure(mixed): ?string)|null */
    public function describe(): ?\Closure
    {
        $describe = $this->data['describe'] ?? null;

        return $describe instanceof \Closure ? $describe : null;
    }

    public function cancellation(): ?CancellationToken
    {
        $cancellation = $this->data['cancellation'] ?? null;

        return $cancellation instanceof CancellationToken ? $cancellation : null;
    }

    public function isWorkflow(): bool
    {
        return ($this->data['workflow'] ?? false) === true;
    }
}
