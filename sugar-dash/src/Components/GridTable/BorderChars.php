<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Box-drawing characters for table borders.
 *
 * Mirrors tealeaves BorderChars with all junction types.
 */
final class BorderChars
{
    // Scalar constants for individual character access - Default set
    public const TOP_LEFT_DEFAULT = '┏';
    public const TOP_DEFAULT = '━';
    public const TOP_RIGHT_DEFAULT = '┓';
    public const LEFT_DEFAULT = '┃';
    public const BOTTOM_LEFT_DEFAULT = '┗';
    public const BOTTOM_DEFAULT = '━';
    public const BOTTOM_RIGHT_DEFAULT = '┛';
    public const RIGHT_DEFAULT = '┃';
    public const TOP_MID_DEFAULT = '┳';
    public const BOTTOM_MID_DEFAULT = '┻';
    public const CENTER_DEFAULT = '╋';
    public const LEFT_JUNCTION_DEFAULT = '┣';
    public const RIGHT_JUNCTION_DEFAULT = '┫';
    public const FREEZE_DIVIDER_DEFAULT = '║';
    public const FREEZE_TOP_JUNCTION_DEFAULT = '╥';
    public const FREEZE_BOTTOM_JUNCTION_DEFAULT = '╨';

    // Scalar constants for individual character access - Rounded set
    public const TOP_LEFT_ROUNDED = '╭';
    public const TOP_ROUNDED = '─';
    public const TOP_RIGHT_ROUNDED = '╮';
    public const LEFT_ROUNDED = '│';
    public const BOTTOM_LEFT_ROUNDED = '╰';
    public const BOTTOM_ROUNDED = '─';
    public const BOTTOM_RIGHT_ROUNDED = '╯';
    public const RIGHT_ROUNDED = '│';
    public const TOP_MID_ROUNDED = '┬';
    public const BOTTOM_MID_ROUNDED = '┴';
    public const CENTER_ROUNDED = '┼';
    public const LEFT_JUNCTION_ROUNDED = '├';
    public const RIGHT_JUNCTION_ROUNDED = '┤';
    public const FREEZE_DIVIDER_ROUNDED = '║';
    public const FREEZE_TOP_JUNCTION_ROUNDED = '╥';
    public const FREEZE_BOTTOM_JUNCTION_ROUNDED = '╨';

    public function __construct(
        public readonly string $topLeft,
        public readonly string $top,
        public readonly string $topRight,
        public readonly string $left,
        public readonly string $bottomLeft,
        public readonly string $bottom,
        public readonly string $bottomRight,
        public readonly string $right,
        public readonly string $topMid,
        public readonly string $bottomMid,
        public readonly string $center,
        public readonly string $leftJunction = '┣',
        public readonly string $rightJunction = '┫',
        public readonly string $topJunction = '┳',
        public readonly string $bottomJunction = '┻',
        public readonly string $innerJunction = '╋',
        public readonly string $innerDivider = '┃',
        public readonly string $freezeDivider = '║',
        public readonly string $freezeTopJunction = '╥',
        public readonly string $freezeBottomJunction = '╨',
        public readonly string $freezeInnerJunction = '╫',
    ) {}

    /**
     * Get default heavy border characters.
     */
    public static function default(): self
    {
        static $instance = null;
        return $instance ??= new self(
            topLeft: self::TOP_LEFT_DEFAULT,
            top: self::TOP_DEFAULT,
            topRight: self::TOP_RIGHT_DEFAULT,
            left: self::LEFT_DEFAULT,
            bottomLeft: self::BOTTOM_LEFT_DEFAULT,
            bottom: self::BOTTOM_DEFAULT,
            bottomRight: self::BOTTOM_RIGHT_DEFAULT,
            right: self::RIGHT_DEFAULT,
            topMid: self::TOP_MID_DEFAULT,
            bottomMid: self::BOTTOM_MID_DEFAULT,
            center: self::CENTER_DEFAULT,
            leftJunction: self::LEFT_JUNCTION_DEFAULT,
            rightJunction: self::RIGHT_JUNCTION_DEFAULT,
            freezeDivider: self::FREEZE_DIVIDER_DEFAULT,
            freezeTopJunction: self::FREEZE_TOP_JUNCTION_DEFAULT,
            freezeBottomJunction: self::FREEZE_BOTTOM_JUNCTION_DEFAULT,
        );
    }

    /**
     * Get rounded thin border characters.
     */
    public static function rounded(): self
    {
        static $instance = null;
        return $instance ??= new self(
            topLeft: self::TOP_LEFT_ROUNDED,
            top: self::TOP_ROUNDED,
            topRight: self::TOP_RIGHT_ROUNDED,
            left: self::LEFT_ROUNDED,
            bottomLeft: self::BOTTOM_LEFT_ROUNDED,
            bottom: self::BOTTOM_ROUNDED,
            bottomRight: self::BOTTOM_RIGHT_ROUNDED,
            right: self::RIGHT_ROUNDED,
            topMid: self::TOP_MID_ROUNDED,
            bottomMid: self::BOTTOM_MID_ROUNDED,
            center: self::CENTER_ROUNDED,
            leftJunction: self::LEFT_JUNCTION_ROUNDED,
            rightJunction: self::RIGHT_JUNCTION_ROUNDED,
            freezeDivider: self::FREEZE_DIVIDER_ROUNDED,
            freezeTopJunction: self::FREEZE_TOP_JUNCTION_ROUNDED,
            freezeBottomJunction: self::FREEZE_BOTTOM_JUNCTION_ROUNDED,
        );
    }

    /**
     * Get borderless characters (empty strings).
     */
    public static function borderless(): self
    {
        static $instance = null;
        return $instance ??= new self(
            topLeft: '',
            top: '',
            topRight: '',
            left: '',
            bottomLeft: '',
            bottom: '',
            bottomRight: '',
            right: '',
            topMid: '',
            bottomMid: '',
            center: '',
            leftJunction: '',
            rightJunction: '',
            freezeDivider: '',
            freezeTopJunction: '',
            freezeBottomJunction: '',
        );
    }

    /**
     * Get minimal border characters.
     */
    public static function minimal(): self
    {
        static $instance = null;
        return $instance ??= new self(
            topLeft: ' ',
            top: '─',
            topRight: ' ',
            left: '│',
            bottomLeft: ' ',
            bottom: '─',
            bottomRight: ' ',
            right: '│',
            topMid: ' ',
            bottomMid: ' ',
            center: ' ',
            leftJunction: ' ',
            rightJunction: ' ',
            freezeDivider: '│',
            freezeTopJunction: ' ',
            freezeBottomJunction: ' ',
        );
    }
}
