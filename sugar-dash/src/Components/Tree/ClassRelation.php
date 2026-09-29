<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * A relationship between classes.
 */
final class ClassRelation
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $type = 'association',
        public readonly string $label = '',
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create an association relationship.
     */
    public static function association(string $from, string $to, string $label = ''): self
    {
        return new self(uniqid('', true), $from, $to, 'association', $label);
    }

    /**
     * Create an inheritance relationship.
     */
    public static function inheritance(string $from, string $to): self
    {
        return new self(uniqid('', true), $from, $to, 'inheritance', '');
    }

    /**
     * Create an implementation relationship.
     */
    public static function implementation(string $from, string $to): self
    {
        return new self(uniqid('', true), $from, $to, 'implementation', '');
    }

    /**
     * Create an aggregation relationship.
     */
    public static function aggregation(string $from, string $to, string $label = ''): self
    {
        return new self(uniqid('', true), $from, $to, 'aggregation', $label);
    }

    /**
     * Create a composition relationship.
     */
    public static function composition(string $from, string $to, string $label = ''): self
    {
        return new self(uniqid('', true), $from, $to, 'composition', $label);
    }

    /**
     * Create a dependency relationship.
     */
    public static function dependency(string $from, string $to, string $label = ''): self
    {
        return new self(uniqid('', true), $from, $to, 'dependency', $label);
    }
}
