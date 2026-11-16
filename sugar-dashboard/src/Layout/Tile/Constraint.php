<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Tile;

/**
 * Constraint describes how a child should be sized along a container's axis.
 * Mirrors tealeaves/tealayout_constraint.go constraint struct.
 */
final class Constraint
{
    public function __construct(
        public readonly ConstraintKind $kind = ConstraintKind::Fixed,
        public readonly int $fixedSize = 0,
        public readonly float $flexWeight = 0.0,
        public readonly int $minSize = 0,
        public readonly ?int $maxSize = null, // null = unbounded (finding sugar-dashboard-217: the -1 sentinel made maxSize 0 a silent clamp-to-zero)
        public readonly bool $optional = false,
        public readonly bool $minSizeFit = false,
    ) {}

    public static function fixed(int $size): self
    {
        return new self(ConstraintKind::Fixed, fixedSize: $size);
    }

    public static function flex(float $weight = 1.0): self
    {
        return new self(ConstraintKind::Flex, flexWeight: $weight);
    }

    public static function fit(): self
    {
        return new self(ConstraintKind::Fit);
    }

    public function withMinSize(int $min): self
    {
        return new self(
            kind: $this->kind,
            fixedSize: $this->fixedSize,
            flexWeight: $this->flexWeight,
            minSize: $min,
            maxSize: $this->maxSize,
            optional: $this->optional,
            minSizeFit: $this->minSizeFit,
        );
    }

    /**
     * Cap the size at $max. A non-positive cap means no cap: sizing a
     * visible tile to a zero maximum was the silent clamp-to-zero half of
     * finding sugar-dashboard-217, so 0 now reads as honest no-max.
     */
    public function withMaxSize(int $max): self
    {
        return new self(
            kind: $this->kind,
            fixedSize: $this->fixedSize,
            flexWeight: $this->flexWeight,
            minSize: $this->minSize,
            maxSize: $max <= 0 ? null : $max,
            optional: $this->optional,
            minSizeFit: $this->minSizeFit,
        );
    }

    public function withOptional(bool $optional): self
    {
        return new self(
            kind: $this->kind,
            fixedSize: $this->fixedSize,
            flexWeight: $this->flexWeight,
            minSize: $this->minSize,
            maxSize: $this->maxSize,
            optional: $optional,
            minSizeFit: $this->minSizeFit,
        );
    }

    public function withMinSizeFit(bool $fit): self
    {
        return new self(
            kind: $this->kind,
            fixedSize: $this->fixedSize,
            flexWeight: $this->flexWeight,
            minSize: $this->minSize,
            maxSize: $this->maxSize,
            optional: $this->optional,
            minSizeFit: $fit,
        );
    }
}
