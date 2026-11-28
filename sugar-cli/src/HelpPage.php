<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\SpecView;
use SugarCraft\Core\Concerns\Mutable;

/**
 * The help page of one command in a {@see CommandSpec}, built by
 * {@see HelpText::fromSpec()} and laid out by {@see HelpText::render()}, so
 * spec-driven help and hand-written help share one renderer.
 *
 *     echo HelpText::fromSpec($spec)->render();          // the root command
 *     echo HelpText::fromSpec($spec, 'run')->render();   // `candy-code run`
 *
 * The page lists, in order: the usage synopsis, the command's description,
 * its subcommands, its ungrouped options, one section per option group
 * ({@see Option::withGroup()}) and the persistent options it inherits from
 * its ancestors. Each option row carries its short alias, value placeholder,
 * a `...` when repeatable, and bracketed notes for required, the
 * environment variable, the default and the allowed values.
 *
 * Every English word the page adds (section titles, the notes, the
 * synopsis placeholders) is a label the caller can replace through
 * {@see withLabels()}: sugar-cli ships no catalog, so a localized CLI passes
 * its own words in.
 *
 * Immutable: every `with*()` returns a new instance. Original work: fang
 * renders cobra's command tree; this renders sugar-cli's own spec in the
 * same shape. Mirrors charmbracelet/fang's help screen in shape only.
 */
final class HelpPage
{
    use Mutable;

    /** The label keys {@see withLabels()} accepts, with their English defaults. */
    public const DEFAULT_LABELS = [
        'usage' => 'usage',
        'commands' => 'commands',
        'options' => 'options',
        'global options' => 'global options',
        'required' => 'required',
        'env' => 'env',
        'default' => 'default',
        'possible values' => 'possible values',
        'command' => 'command',
        'arg' => 'arg',
    ];

    /**
     * @param non-empty-list<CommandSpec> $path root first, the documented command last
     * @param array<string, string> $labels
     */
    private function __construct(
        private readonly array $path,
        private readonly ?Theme $theme,
        private readonly int|AutoWidth|null $width,
        private readonly array $labels,
        private readonly bool|\Closure $titleCase,
    ) {}

    /**
     * The page of `$root`, or of the subcommand that `$names` walks down to.
     * {@see HelpText::fromSpec()} is the same call.
     *
     * @throws \InvalidArgumentException when a name is not a subcommand of its parent
     */
    public static function new(CommandSpec $root, string ...$names): self
    {
        return new self(SpecView::path($root, array_values($names)), null, AutoWidth::Auto, self::DEFAULT_LABELS, true);
    }

    /** Theme for the headings and option keys; null (the default) detects one. */
    public function withTheme(?Theme $theme): self
    {
        return $this->mutate(['theme' => $theme]);
    }

    /**
     * Cell width to wrap to, as {@see HelpText::render()} takes it: an int,
     * `AutoWidth::Auto` (the default, the terminal width) or null (never wrap).
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public function withWidth(int|AutoWidth|null $width): self
    {
        if (is_int($width) && $width < 1) {
            throw new \InvalidArgumentException(sprintf('HelpPage width must be at least 1 cell (or null to disable wrapping); got %d.', $width));
        }
        return $this->mutate(['width' => $width]);
    }

    /**
     * Replace some of the page's own words, keyed as in
     * {@see DEFAULT_LABELS}. Keys not given keep their current text.
     *
     * @param array<string, string> $labels
     * @throws \InvalidArgumentException on a key that is not a label
     */
    public function withLabels(array $labels): self
    {
        $unknown = array_diff(array_map('strval', array_keys($labels)), array_keys(self::DEFAULT_LABELS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown help label "%s"; expected one of: %s.',
                reset($unknown),
                implode(', ', array_keys(self::DEFAULT_LABELS)),
            ));
        }
        return $this->mutate(['labels' => array_map('strval', $labels) + $this->labels]);
    }

    /**
     * Title styling of the headings, as {@see HelpText::render()} takes it:
     * true uppercases (the default), false prints them as given, a closure
     * maps each one.
     */
    public function withTitleCase(bool|\Closure $titleCase): self
    {
        return $this->mutate(['titleCase' => $titleCase]);
    }

    /** The documented command. */
    public function command(): CommandSpec
    {
        return $this->path[count($this->path) - 1];
    }

    /** The full usage synopsis, e.g. `candy-code run [options] <arg>`. */
    public function usage(): string
    {
        $tail = SpecView::synopsisTail($this->path, $this->labels['options'], $this->labels['command'], $this->labels['arg']);
        $line = SpecView::commandLine($this->path);
        return $tail === '' ? $line : $line . ' ' . $tail;
    }

    /**
     * The page's sections as {@see HelpText::render()} takes them: title =>
     * row key => description. Sections with no rows are left out.
     *
     * @return array<string, array<string, string>>
     */
    public function sections(): array
    {
        $command = $this->command();
        $sections = [];
        foreach ($command->subcommands() as $sub) {
            $sections[$this->labels['commands']][$sub->name()] = $sub->description();
        }
        foreach ($command->groups() as $group => $options) {
            $title = (string) $group === '' ? $this->labels['options'] : (string) $group;
            $sections[$title] = ($sections[$title] ?? []) + $this->rows($options);
        }
        $inherited = SpecView::inherited($this->path);
        if ($inherited !== []) {
            $title = $this->labels['global options'];
            $sections[$title] = ($sections[$title] ?? []) + $this->rows($inherited);
        }
        return $sections;
    }

    /** Render the page through {@see HelpText::render()}. */
    public function render(): string
    {
        return HelpText::render(
            usage: $this->usage(),
            sections: $this->sections(),
            description: $this->command()->description(),
            theme: $this->theme,
            width: $this->width,
            usageLabel: $this->labels['usage'],
            titleCase: $this->titleCase,
        );
    }

    /**
     * @param list<Option> $options
     * @return array<string, string>
     */
    private function rows(array $options): array
    {
        $alignShort = false;
        foreach ($options as $option) {
            $alignShort = $alignShort || $option->short() !== null;
        }
        $rows = [];
        foreach ($options as $option) {
            $rows[SpecView::optionKey($option, $alignShort)] = $this->describe($option);
        }
        return $rows;
    }

    private function describe(Option $option): string
    {
        $notes = [];
        if ($option->required()) {
            $notes[] = '[' . $this->labels['required'] . ']';
        }
        if ($option->env() !== null) {
            $notes[] = '[' . $this->labels['env'] . ': ' . $option->env() . ']';
        }
        $default = SpecView::defaultText($option);
        if ($default !== null) {
            $notes[] = '[' . $this->labels['default'] . ': ' . $default . ']';
        }
        if ($option->choices() !== []) {
            $notes[] = '[' . $this->labels['possible values'] . ': ' . implode(', ', $option->choices()) . ']';
        }
        return implode(' ', array_filter([$option->description(), ...$notes], static fn (string $s): bool => $s !== ''));
    }
}
