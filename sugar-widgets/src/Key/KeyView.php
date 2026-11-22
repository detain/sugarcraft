<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Key;

use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Widgets\Lang;

/**
 * The {@see KeyMap} a sugar-core {@see KeyBindings} registry presents to the
 * {@see \SugarCraft\Widgets\Help\Help} footer: every help row is GENERATED
 * from the registry, so no app hand-maintains a key column that drifts from
 * what dispatch actually answers to (AUD D11: help text was hand-written in
 * five places). A user override folded on through `KeyBindings::fromFile()`
 * lands in the help the moment the registry does.
 *
 * Each action becomes one {@see Binding}: its keys are the registry's
 * canonical chords, its help key is `KeyBindings::label($id, $keySeparator)`
 * (`↑/k`, `ctrl+s`), and its description is the text declared through
 * `KeyBindings::bind()` / `withHelp()`, falling back to the action id when
 * none was given (still derived, never invented). An action with no chords
 * is a disabled binding, which the footer skips unless `withShowDisabled()`
 * asks to document it. Dispatch stays on `KeyBindings::matches()`; these
 * bindings exist to be rendered.
 *
 * ```php
 * $keys = KeyBindings::new()
 *     ->bind('up', ['up', 'k'], 'move up')
 *     ->bind('quit', ['q', 'ctrl+c'], 'quit');
 * echo Help::new()->fullView(KeyView::new($keys)->withColumnSize(4));
 * ```
 *
 * Mirrors charmbracelet/bubbles help.KeyMap (ShortHelp / FullHelp), with the
 * bindings supplied by the registry instead of a hand-built struct.
 */
final class KeyView implements KeyMap
{
    /**
     * @param list<string>|null       $short   action ids on the short row; null = every action
     * @param list<list<string>>|null $columns action ids per full-help column; null = auto
     */
    private function __construct(
        public readonly KeyBindings $bindings,
        public readonly ?array $short = null,
        public readonly ?array $columns = null,
        public readonly int $columnSize = 0,
        public readonly string $keySeparator = '/',
    ) {
    }

    /**
     * House factory: a view over every action in $bindings, one column.
     */
    public static function new(KeyBindings $bindings): self
    {
        return new self($bindings);
    }

    /** Swap the registry (a new override layer, a locale switch). */
    public function withBindings(KeyBindings $bindings): self
    {
        return $this->mutate(['bindings' => $bindings]);
    }

    /**
     * Choose which actions the short row shows, in order. No ids restores
     * the default (every action). Mirrors charmbracelet/bubbles KeyMap.ShortHelp.
     */
    public function withShort(string ...$ids): self
    {
        return $this->mutate(['short' => $ids === [] ? null : array_values($ids)]);
    }

    /**
     * Lay the full help out explicitly: one list of action ids per column.
     * An empty array restores the automatic layout ({@see withColumnSize()}).
     * Mirrors charmbracelet/bubbles KeyMap.FullHelp.
     *
     * @param list<list<string>> $columns
     */
    public function withColumns(array $columns): self
    {
        if ($columns === []) {
            return $this->mutate(['columns' => null]);
        }
        $clean = [];
        foreach ($columns as $column) {
            if (!\is_array($column)) {
                throw new \InvalidArgumentException(Lang::t('key_view.bad_columns'));
            }
            $ids = [];
            foreach ($column as $id) {
                if (!\is_string($id)) {
                    throw new \InvalidArgumentException(Lang::t('key_view.bad_columns'));
                }
                $ids[] = $id;
            }
            $clean[] = $ids;
        }

        return $this->mutate(['columns' => $clean]);
    }

    /**
     * Automatic layout: chunk every action, in declaration order, into
     * columns of at most $rows bindings. 0 (the default) keeps one column.
     */
    public function withColumnSize(int $rows): self
    {
        if ($rows < 0) {
            throw new \InvalidArgumentException(Lang::t('key_view.column_size_nonneg'));
        }

        return $this->mutate(['columnSize' => $rows]);
    }

    /** Separator between one action's chords in its help key (default `/`). */
    public function withKeySeparator(string $separator): self
    {
        return $this->mutate(['keySeparator' => $separator]);
    }

    /**
     * The generated binding for one action id.
     */
    public function binding(string $id): Binding
    {
        $chords = $this->bindings->tokens($id);
        $desc = $this->bindings->help($id);

        return Binding::new(
            keys: $chords,
            help: new Help($this->bindings->label($id, $this->keySeparator), $desc !== '' ? $desc : $id),
            disabled: $chords === [],
        );
    }

    /** @return list<Binding> */
    public function shortHelp(): array
    {
        return $this->bindingsFor($this->short ?? $this->bindings->actions());
    }

    /** @return list<list<Binding>> */
    public function fullHelp(): array
    {
        if ($this->columns !== null) {
            return array_map(fn(array $ids): array => $this->bindingsFor($ids), $this->columns);
        }
        $all = $this->bindingsFor($this->bindings->actions());
        if ($all === []) {
            return [];
        }

        return $this->columnSize === 0 ? [$all] : array_chunk($all, $this->columnSize);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Binding>
     */
    private function bindingsFor(array $ids): array
    {
        return array_map(fn(string $id): Binding => $this->binding($id), $ids);
    }

    /**
     * @param array<string,mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge([
            'bindings'     => $this->bindings,
            'short'        => $this->short,
            'columns'      => $this->columns,
            'columnSize'   => $this->columnSize,
            'keySeparator' => $this->keySeparator,
        ], $changes));
    }
}
