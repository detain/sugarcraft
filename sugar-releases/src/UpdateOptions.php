<?php

declare(strict_types=1);

namespace SugarCraft\Releases;

/**
 * The parsed shape of `self-update [--check] [--rollback] [--allow-unsigned]
 * [--channel=stable|dev]`, free of any CLI framework: the host parses its own
 * argv and hands the engine this.
 *
 * {@see validate()} states the usage laws once (flags exclusivity, channel
 * names, no operands) as translation keys the engine reports with exit 2.
 */
final class UpdateOptions
{
    public const CHANNEL_STABLE = 'stable';

    public const CHANNEL_DEV = 'dev';

    /** @param list<string> $operands */
    private function __construct(
        public readonly bool $check,
        public readonly bool $rollback,
        public readonly bool $allowUnsigned,
        public readonly ?string $channel,
        public readonly array $operands,
    ) {
    }

    public static function new(): self
    {
        return new self(false, false, false, null, []);
    }

    public function withCheck(bool $check = true): self
    {
        return new self($check, $this->rollback, $this->allowUnsigned, $this->channel, $this->operands);
    }

    public function withRollback(bool $rollback = true): self
    {
        return new self($this->check, $rollback, $this->allowUnsigned, $this->channel, $this->operands);
    }

    public function withAllowUnsigned(bool $allowUnsigned = true): self
    {
        return new self($this->check, $this->rollback, $allowUnsigned, $this->channel, $this->operands);
    }

    /** null = default channel from the current version. Validity is {@see validate()}'s call. */
    public function withChannel(?string $channel): self
    {
        return new self($this->check, $this->rollback, $this->allowUnsigned, $channel, $this->operands);
    }

    /** @param list<string> $operands */
    public function withOperands(array $operands): self
    {
        return new self($this->check, $this->rollback, $this->allowUnsigned, $this->channel, \array_values($operands));
    }

    /**
     * The first usage violation as [translation key, params], or null when the
     * options are runnable. Order is the original verb's: exclusivity, channel,
     * operands.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public function validate(): ?array
    {
        if ($this->check && ($this->rollback || $this->allowUnsigned)) {
            return ['selfupdate.flags_exclusive', []];
        }
        if ($this->channel !== null && $this->channel !== self::CHANNEL_STABLE && $this->channel !== self::CHANNEL_DEV) {
            return ['selfupdate.channel_invalid', ['value' => $this->channel]];
        }
        if ($this->operands !== []) {
            return ['selfupdate.unexpected_operand', ['operand' => $this->operands[0]]];
        }

        return null;
    }
}
