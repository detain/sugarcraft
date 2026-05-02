<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles;

use CandyCore\Core\Util\Width as WidthUtil;

/**
 * A single positioned layer within a {@see Canvas}. Holds the
 * pre-rendered content block plus its (x, y) origin and z-index.
 *
 * Layers are immutable value objects — `withX()` / `withY()` /
 * `withZ()` return modified copies rather than mutating in place,
 * mirroring the upstream `lipgloss/v2.Layer` API.
 */
final class Layer
{
    public function __construct(
        public readonly string $content,
        public readonly int $x = 0,
        public readonly int $y = 0,
        public readonly int $z = 0,
    ) {}

    public static function new(string $content): self
    {
        return new self($content);
    }

    public function withX(int $x): self { return new self($this->content, $x, $this->y, $this->z); }
    public function withY(int $y): self { return new self($this->content, $this->x, $y, $this->z); }
    public function withZ(int $z): self { return new self($this->content, $this->x, $this->y, $z); }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->content === '' ? [''] : explode("\n", $this->content);
    }

    public function width(): int
    {
        $max = 0;
        foreach ($this->lines() as $l) {
            $w = WidthUtil::string($l);
            if ($w > $max) {
                $max = $w;
            }
        }
        return $max;
    }

    public function height(): int
    {
        return count($this->lines());
    }
}
