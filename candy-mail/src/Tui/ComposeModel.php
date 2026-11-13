<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tui;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Mail\Attachment;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\Lang;

/**
 * Interactive compose form: `candy-mail tui`.
 *
 * Mirrors the compose screen of charmbracelet/pop (From/To/Cc/Bcc/Subject/Body/
 * Attachments, send, quit) on the sugar-core Model contract. Send is a
 * {@see Cmd} - the wire work never runs inside update() or view() - and goes
 * through an injected closure so the model is transport-agnostic and testable
 * with no network: the bin passes a Mailer-backed sender, tests a recorder.
 *
 * Keys: Tab/Shift+Tab/Up/Down change field, Enter advances (newline in the
 * body), Ctrl+S sends, Ctrl+P toggles the offline MIME preview, Ctrl+U clears
 * the field, Ctrl+Q/Ctrl+C/Esc quit (twice when unsent edits exist).
 * Address fields take comma-separated lists; a display name containing a
 * comma is therefore not expressible here (use the CLI flags for that).
 */
final class ComposeModel implements Model
{
    public const FIELDS = ['from', 'to', 'cc', 'bcc', 'subject', 'body', 'attach'];

    /**
     * @param \Closure(Email): string      $send   delivers the message, returns the transport name
     * @param \Closure(Email): string      $render offline MIME text for the preview
     * @param array<string, TextBuffer>    $fields keyed by FIELDS names
     */
    private function __construct(
        private readonly \Closure $send,
        private readonly \Closure $render,
        public readonly array $fields,
        public readonly int $focus = 0,
        public readonly string $mode = 'edit',
        public readonly string $status = '',
        public readonly bool $statusIsError = false,
        public readonly string $preview = '',
        public readonly bool $dirty = false,
        public readonly bool $quitArmed = false,
        public readonly int $cols = 80,
        public readonly int $rows = 24,
    ) {
    }

    /**
     * @param \Closure(Email): string $send
     * @param \Closure(Email): string $render
     * @param array<string, string>   $prefill field name => initial text
     */
    public static function new(\Closure $send, \Closure $render, array $prefill = []): self
    {
        $fields = [];
        foreach (self::FIELDS as $name) {
            $fields[$name] = TextBuffer::new($prefill[$name] ?? '', $name === 'body');
        }

        return new self($send, $render, $fields);
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /** Name of the focused field. */
    public function focused(): string
    {
        return self::FIELDS[$this->focus];
    }

    /**
     * Compose the message from the form; the same gate a send goes through.
     *
     * @throws \InvalidArgumentException|\RuntimeException on a bad address, header or attachment
     */
    public function email(): Email
    {
        $list = static fn (string $v): array => \array_values(\array_filter(
            \array_map('trim', \explode(',', $v)),
            static fn (string $s): bool => $s !== '',
        ));
        $val = fn (string $n): string => $this->fields[$n]->value();

        $attachments = [];
        foreach ($list($val('attach')) as $path) {
            $attachments[] = Attachment::fromPath($path);
        }
        $subject = \trim($val('subject'));
        $body    = $val('body');

        return new Email(
            from:        $list($val('from')),
            to:          $list($val('to')),
            subject:     $subject === '' ? null : $subject,
            body:        $body === '' ? null : $body,
            cc:          $list($val('cc')),
            bcc:         $list($val('bcc')),
            attachments: $attachments,
        );
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->with(['cols' => $msg->cols, 'rows' => $msg->rows]), null];
        }
        if ($msg instanceof SendResultMsg) {
            if ($msg->error !== null) {
                return [$this->with([
                    'mode' => 'edit', 'status' => Lang::t('tui.send_failed', ['message' => $msg->error]),
                    'statusIsError' => true,
                ]), null];
            }

            return [$this->with([
                'mode' => 'edit', 'dirty' => false, 'quitArmed' => false, 'statusIsError' => false,
                'status' => Lang::t('tui.sent', ['transport' => $msg->transport]),
            ]), null];
        }
        if ($this->mode === 'sending') {
            return [$this, null];
        }
        if ($msg instanceof PasteMsg) {
            return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->insert($msg->content)), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->key($msg);
        }

        return [$this, null];
    }

    /** @return array{0: Model, 1: ?\Closure} */
    private function key(KeyMsg $k): array
    {
        $s = $k->string();

        if ($s === 'ctrl+c' || $s === 'ctrl+q' || ($s === 'escape' && $this->mode === 'edit')) {
            if ($this->dirty && !$this->quitArmed) {
                return [$this->with(['quitArmed' => true, 'status' => Lang::t('tui.quit_confirm'), 'statusIsError' => true]), null];
            }

            return [$this, Cmd::quit()];
        }
        if ($s === 'ctrl+s') {
            return $this->dispatch();
        }
        if ($s === 'ctrl+p' || ($s === 'escape' && $this->mode === 'preview')) {
            return [$this->togglePreview(), null];
        }
        if ($this->mode === 'preview') {
            return [$this, null];
        }

        $buf = $this->fields[$this->focused()];
        $body = $this->focused() === 'body';

        switch ($s) {
            case 'tab':
                return [$this->move(1), null];
            case 'shift+tab':
                return [$this->move(-1), null];
            case 'enter':
                return $body
                    ? [$this->edit(fn (TextBuffer $b): TextBuffer => $b->insert("\n")), null]
                    : [$this->move(1), null];
            case 'up':
                return ($body && !$buf->onFirstLine())
                    ? [$this->edit(fn (TextBuffer $b): TextBuffer => $b->up(), false), null]
                    : [$this->move(-1), null];
            case 'down':
                return ($body && !$buf->onLastLine())
                    ? [$this->edit(fn (TextBuffer $b): TextBuffer => $b->down(), false), null]
                    : [$this->move(1), null];
            case 'left':
                return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->left(), false), null];
            case 'right':
                return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->right(), false), null];
            case 'home':
                return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->home(), false), null];
            case 'end':
                return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->end(), false), null];
            case 'backspace':
                return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->backspace()), null];
            case 'delete':
                return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->delete()), null];
            case 'ctrl+u':
                return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->clear()), null];
            case 'space':
                return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->insert(' ')), null];
        }
        if ($k->type === KeyType::Char && !$k->ctrl && !$k->alt && $k->rune !== '') {
            return [$this->edit(fn (TextBuffer $b): TextBuffer => $b->insert($k->rune)), null];
        }

        return [$this, null];
    }

    /** @return array{0: Model, 1: ?\Closure} */
    private function dispatch(): array
    {
        try {
            $email = $this->email();
        } catch (\Throwable $e) {
            return [$this->with([
                'status' => Lang::t('tui.send_failed', ['message' => $e->getMessage()]),
                'statusIsError' => true, 'mode' => 'edit',
            ]), null];
        }
        $send = $this->send;
        $cmd  = static function () use ($send, $email): SendResultMsg {
            try {
                return new SendResultMsg(null, $send($email));
            } catch (\Throwable $e) {
                return new SendResultMsg($e->getMessage());
            }
        };

        return [$this->with(['mode' => 'sending', 'status' => Lang::t('tui.sending'), 'statusIsError' => false, 'quitArmed' => false]), $cmd];
    }

    private function togglePreview(): self
    {
        if ($this->mode === 'preview') {
            return $this->with(['mode' => 'edit', 'status' => '', 'statusIsError' => false]);
        }
        try {
            $text = ($this->render)($this->email());
        } catch (\Throwable $e) {
            return $this->with([
                'status' => Lang::t('tui.send_failed', ['message' => $e->getMessage()]),
                'statusIsError' => true,
            ]);
        }

        return $this->with(['mode' => 'preview', 'preview' => $text, 'status' => '', 'statusIsError' => false]);
    }

    private function move(int $by): self
    {
        $n = \count(self::FIELDS);

        return $this->with(['focus' => ($this->focus + $by + $n) % $n, 'quitArmed' => false]);
    }

    /** @param \Closure(TextBuffer): TextBuffer $fn */
    private function edit(\Closure $fn, bool $changes = true): self
    {
        $name   = $this->focused();
        $fields = $this->fields;
        $fields[$name] = $fn($fields[$name]);
        $o = ['fields' => $fields, 'quitArmed' => false];
        if ($changes) {
            $o['dirty'] = true;
            $o['status'] = '';
            $o['statusIsError'] = false;
        }

        return $this->with($o);
    }

    /** @param array<string, mixed> $o */
    private function with(array $o): self
    {
        return new self(
            $this->send,
            $this->render,
            $o['fields'] ?? $this->fields,
            $o['focus'] ?? $this->focus,
            $o['mode'] ?? $this->mode,
            $o['status'] ?? $this->status,
            $o['statusIsError'] ?? $this->statusIsError,
            $o['preview'] ?? $this->preview,
            $o['dirty'] ?? $this->dirty,
            $o['quitArmed'] ?? $this->quitArmed,
            $o['cols'] ?? $this->cols,
            $o['rows'] ?? $this->rows,
        );
    }

    public function view(): string
    {
        $w   = \max(20, $this->cols);
        $out = ["\x1b[1m" . \mb_strimwidth(Lang::t('tui.title'), 0, $w, '') . "\x1b[22m", ''];

        if ($this->mode === 'preview') {
            $out[] = \mb_strimwidth(Lang::t('tui.preview_title'), 0, $w, '');
            $out[] = '';
            $room  = \max(1, $this->rows - 6);
            $lines = \preg_split('/\r\n|\n/', $this->preview) ?: [];
            foreach (\array_slice($lines, 0, $room) as $line) {
                $out[] = \mb_strimwidth(Sanitize::untrusted($line), 0, $w, '');
            }
            $out[] = '';
            $out[] = "\x1b[2m" . \mb_strimwidth(Lang::t('tui.help_preview'), 0, $w, '') . "\x1b[22m";

            return \implode("\n", $out);
        }

        $labels = [];
        foreach (self::FIELDS as $n) {
            $labels[$n] = Lang::t('tui.field.' . $n);
        }
        $lw = \max(\array_map('mb_strwidth', $labels));
        $bodyRoom = \max(3, $this->rows - 13);

        foreach (self::FIELDS as $i => $n) {
            $focused = $i === $this->focus;
            $label   = $labels[$n] . \str_repeat(' ', $lw - \mb_strwidth($labels[$n])) . ' ';
            $label   = $focused ? "\x1b[7m" . $label . "\x1b[27m" : $label;
            $avail   = $w - $lw - 1;
            $layout  = $this->fields[$n]->layout();

            if ($n === 'body') {
                $out[] = $label;
                $start = \max(0, $layout['row'] - $bodyRoom + 1);
                foreach (\array_slice($layout['lines'], $start, $bodyRoom) as $r => $chars) {
                    $out[] = '  ' . $this->paintLine($chars, $focused && $start + $r === $layout['row'] ? $layout['col'] : null, $w - 2);
                }
                continue;
            }
            $chars = $layout['lines'][0];
            if ($n === 'attach' && $chars === [] && !$focused) {
                $out[] = $label . "\x1b[2m" . \mb_strimwidth(Lang::t('tui.attach_hint'), 0, \max(1, $avail), '') . "\x1b[22m";
                continue;
            }
            $out[] = $label . $this->paintLine($chars, $focused ? $layout['col'] : null, $avail);
        }

        $out[] = '';
        if ($this->status !== '') {
            $line  = \mb_strimwidth(Sanitize::untrusted($this->status), 0, $w, '...');
            $out[] = ($this->statusIsError ? "\x1b[31m" : "\x1b[32m") . $line . "\x1b[39m";
        }
        $out[] = "\x1b[2m" . \mb_strimwidth(Lang::t('tui.help_edit'), 0, $w, '') . "\x1b[22m";

        return \implode("\n", $out);
    }

    /**
     * One visible line, horizontally scrolled so the cursor stays in view,
     * with the cursor cell painted reverse.
     *
     * @param list<string> $chars
     */
    private function paintLine(array $chars, ?int $cursor, int $width): string
    {
        $width = \max(1, $width);
        $start = $cursor !== null ? \max(0, $cursor - $width + 1) : 0;
        $show  = \array_slice($chars, $start, $width);
        if ($cursor === null) {
            return \implode('', $show);
        }
        $c      = $cursor - $start;
        $before = \implode('', \array_slice($show, 0, $c));
        $at     = $show[$c] ?? ' ';
        $after  = \implode('', \array_slice($show, $c + 1));

        return $before . "\x1b[7m" . $at . "\x1b[27m" . $after;
    }
}
