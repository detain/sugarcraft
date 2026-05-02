<?php

declare(strict_types=1);

namespace CandyCore\Prompt;

/**
 * One page of fields in a multi-page {@see Form}.
 *
 * Mirrors charmbracelet/huh's `huh.Group`. Carries a title, optional
 * description, and an optional `hideFunc` predicate that the runtime
 * evaluates on page transitions — when it returns true the group is
 * skipped entirely.
 *
 * Construct via {@see new()} (variadic Field) or {@see fromList()}.
 *
 * Use the chainable `withTitle` / `withDescription` / `withHideFunc`
 * setters to build up a group:
 *
 * ```php
 * $page1 = Group::new(
 *     Input::new('name')->withTitle('Your name'),
 *     Confirm::new('agree')->withTitle('Agree?'),
 * )
 *     ->withTitle('Step 1')
 *     ->withDescription('Tell us about yourself.');
 *
 * $page2 = Group::new(
 *     Note::new('thanks')->withTitle('Thank you!'),
 * )
 *     ->withHideFunc(static fn(array $values): bool => empty($values['agree']));
 * ```
 */
final class Group
{
    /**
     * @param list<Field>                            $fields
     * @param ?\Closure(array<string,mixed>): bool   $hideFunc
     */
    private function __construct(
        public readonly array $fields,
        public readonly string $title,
        public readonly string $description,
        public readonly ?\Closure $hideFunc,
    ) {}

    public static function new(Field ...$fields): self
    {
        return new self(array_values($fields), '', '', null);
    }

    /** @param list<Field> $fields */
    public static function fromList(array $fields): self
    {
        return new self(array_values($fields), '', '', null);
    }

    public function withTitle(string $title): self
    {
        return new self($this->fields, $title, $this->description, $this->hideFunc);
    }

    public function withDescription(string $desc): self
    {
        return new self($this->fields, $this->title, $desc, $this->hideFunc);
    }

    /**
     * Predicate evaluated on page transitions; receives the values
     * collected so far (keyed by field key) and returns true to hide
     * the group. Hidden groups are skipped — both their fields and
     * their values are excluded from the final form result.
     *
     * @param ?\Closure(array<string,mixed>): bool $fn
     */
    public function withHideFunc(?\Closure $fn): self
    {
        return new self($this->fields, $this->title, $this->description, $fn);
    }

    /** @param array<string,mixed> $values */
    public function isHidden(array $values): bool
    {
        return $this->hideFunc !== null && ($this->hideFunc)($values) === true;
    }

    /** @param list<Field>|null $fields */
    public function withFields(array $fields): self
    {
        return new self(array_values($fields), $this->title, $this->description, $this->hideFunc);
    }
}
