<?php

declare(strict_types=1);

namespace SugarCraft\Prompt;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Form-level key bindings — defines which keys advance / retreat /
 * submit / abort a {@see Form}.
 *
 * Mirrors the long-requested upstream charmbracelet/huh #272
 * ("Overriding KeyMaps and KeyBinds"). Construct via the named-args
 * constructor (with sensible defaults from {@see KeyMap::default()})
 * and pass to {@see Form::withKeyMap()}.
 *
 * Every binding is a list of `KeyMsg`-shaped predicates: each entry
 * matches when its `KeyType` (and optional `rune` for `Char`-typed
 * entries, plus `ctrl` / `alt` modifiers) matches the inbound message.
 *
 * The `next` binding is consulted before `submit` — a key that's
 * present in both lists triggers `next` on a non-final field and
 * `submit` on the last field, so users can rebind both to the same
 * key (e.g. Enter advances on every field, submits on the last).
 */
final class KeyMap
{
    /**
     * @param list<array{type:KeyType,rune?:string,ctrl?:bool,alt?:bool}> $next   Move focus to the next field.
     * @param list<array{type:KeyType,rune?:string,ctrl?:bool,alt?:bool}> $prev   Move focus to the previous field.
     * @param list<array{type:KeyType,rune?:string,ctrl?:bool,alt?:bool}> $submit Submit on the last non-skippable field of the last group.
     * @param list<array{type:KeyType,rune?:string,ctrl?:bool,alt?:bool}> $abort  Cancel the form.
     */
    public function __construct(
        public readonly array $next,
        public readonly array $prev,
        public readonly array $submit,
        public readonly array $abort,
    ) {}

    /**
     * Default huh-style bindings:
     *
     *   next   — Tab, Down arrow
     *   prev   — Shift+Tab (Tab + alt), Up arrow
     *   submit — Enter (only effective on the last field of the last group)
     *   abort  — Escape, Ctrl-C
     */
    public static function default(): self
    {
        return new self(
            next: [
                ['type' => KeyType::Tab,  'alt' => false, 'ctrl' => false],
                ['type' => KeyType::Down, 'ctrl' => false],
            ],
            prev: [
                ['type' => KeyType::Tab, 'alt' => true],
                ['type' => KeyType::Up,  'ctrl' => false],
            ],
            submit: [
                ['type' => KeyType::Enter],
            ],
            abort: [
                ['type' => KeyType::Escape],
                ['type' => KeyType::Char, 'rune' => 'c', 'ctrl' => true],
            ],
        );
    }

    /** True when `$msg` matches any of the entries in the `next` list. */
    public function isNext(KeyMsg $msg): bool   { return self::anyMatches($this->next,   $msg); }
    /** True when `$msg` matches any of the entries in the `prev` list. */
    public function isPrev(KeyMsg $msg): bool   { return self::anyMatches($this->prev,   $msg); }
    /** True when `$msg` matches any of the entries in the `submit` list. */
    public function isSubmit(KeyMsg $msg): bool { return self::anyMatches($this->submit, $msg); }
    /** True when `$msg` matches any of the entries in the `abort` list. */
    public function isAbort(KeyMsg $msg): bool  { return self::anyMatches($this->abort,  $msg); }

    /** Replace the `next` binding list. */
    public function withNext(array $entries): self
    {
        return new self($entries, $this->prev, $this->submit, $this->abort);
    }

    /** Replace the `prev` binding list. */
    public function withPrev(array $entries): self
    {
        return new self($this->next, $entries, $this->submit, $this->abort);
    }

    /** Replace the `submit` binding list. */
    public function withSubmit(array $entries): self
    {
        return new self($this->next, $this->prev, $entries, $this->abort);
    }

    /** Replace the `abort` binding list. */
    public function withAbort(array $entries): self
    {
        return new self($this->next, $this->prev, $this->submit, $entries);
    }

    /**
     * @param list<array{type:KeyType,rune?:string,ctrl?:bool,alt?:bool}> $entries
     */
    private static function anyMatches(array $entries, KeyMsg $msg): bool
    {
        foreach ($entries as $entry) {
            if (self::matches($entry, $msg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{type:KeyType,rune?:string,ctrl?:bool,alt?:bool} $entry
     */
    private static function matches(array $entry, KeyMsg $msg): bool
    {
        if ($entry['type'] !== $msg->type) {
            return false;
        }
        if ($msg->type === KeyType::Char && isset($entry['rune'])) {
            if ($entry['rune'] !== $msg->rune) {
                return false;
            }
        }
        if (isset($entry['ctrl']) && $entry['ctrl'] !== $msg->ctrl) {
            return false;
        }
        if (isset($entry['alt']) && $entry['alt'] !== $msg->alt) {
            return false;
        }
        return true;
    }
}
