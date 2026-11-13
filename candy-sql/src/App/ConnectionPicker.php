<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Sql\Lang;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

/**
 * Launch-time picker over the saved connections (SQL-O02b, candy-sql-once-002).
 *
 * Runs as its own Program before the main app: a no-arg interactive launch
 * with at least one saved connection dials from here instead of printing the
 * usage refusal. Enter records the choice and quits; the launcher reads
 * {@see pickedName()} off the final model (Program::run() returns it) and
 * expands the entry into connection flags.
 *
 * The caller is responsible for the non-tty carve-out (stream_isatty at the
 * bin level): pipes keep the named exit-2 usage refusal untouched.
 */
final class ConnectionPicker implements Model
{
    /** @var list<string> */
    private readonly array $names;

    /** @var array<string, array<string, string>> */
    private readonly array $entries;

    /**
     * @param array<string, array<string, string>> $entries name -> settings, as {@see \SugarCraft\Sql\Db\ConnectionStore::all()} yields them
     */
    public function __construct(
        array $entries,
        private readonly int $cursor = 0,
        private readonly ?string $picked = null,
    ) {
        $names = array_keys($entries);
        $this->names = $names;
        $this->entries = $entries;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * The chosen connection name, or null when the picker was cancelled.
     */
    public function pickedName(): ?string
    {
        return $this->picked;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this, null];
        }

        if (!($msg instanceof KeyMsg) || $this->names === []) {
            return [$this, null];
        }

        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return [$this->withCursor(max(0, $this->cursor - 1)), null];
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return [$this->withCursor(min(count($this->names) - 1, $this->cursor + 1)), null];
        }
        if ($msg->type === KeyType::Enter || $msg->type === KeyType::Space) {
            return [$this->withPicked($this->names[$this->cursor] ?? null), Cmd::quit()];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::quit()];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $lines = [
            Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('candy-sql')
            . ' — ' . Style::new()->bold()->render(Lang::t('picker.title')),
            '',
        ];

        foreach ($this->names as $index => $name) {
            $isCursor = $index === $this->cursor;
            $prefix = $isCursor ? '▸ ' : '  ';
            $label = $isCursor
                ? Style::new()->bold()->foreground(Color::hex('#4ade80'))->render($name)
                : Style::new()->foreground(Color::hex('#e5e7eb'))->render($name);
            $meta = Style::new()->foreground(Color::hex('#6b7280'))->render($this->summary($name));
            $lines[] = $prefix . $label . '  ' . $meta;
        }

        $lines[] = '';
        $lines[] = Style::new()->foreground(Color::hex('#6b7280'))->render(
            Lang::t('picker.help'),
        );

        return implode("\n", $lines);
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    private function withCursor(int $cursor): self
    {
        return new self($this->entries, $cursor, $this->picked);
    }

    private function withPicked(?string $picked): self
    {
        return new self($this->entries, $this->cursor, $picked);
    }

    /**
     * One-line dial target preview: user@host:port/db for servers, the file
     * for SQLite. Never includes anything password-like — the entry schema
     * keeps secrets in env references, and the picker prints names only.
     */
    private function summary(string $name): string
    {
        $entry = $this->entries[$name];
        if (($entry['driver'] ?? '') === 'sqlite') {
            return 'sqlite · ' . ($entry['db'] ?? '?');
        }
        $user = isset($entry['user']) ? $entry['user'] . '@' : '';
        $port = isset($entry['port']) ? ':' . $entry['port'] : '';
        $db = isset($entry['db']) ? '/' . $entry['db'] : '';

        return ($entry['driver'] ?? '?') . ' · ' . $user . ($entry['host'] ?? '?') . $port . $db;
    }
}
