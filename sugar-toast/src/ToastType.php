<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * Alert severity / type.
 *
 * @see https://github.com/DaltonSW/bubbleup
 */
enum ToastType: string
{
    case Error   = 'error';
    case Warning = 'warning';
    case Info    = 'info';
    case Success = 'success';

    /**
     * NerdFont icon symbol for this type.
     */
    public function nerdIcon(): string
    {
        return match ($this) {
            self::Error   => '󰅙',
            self::Warning => '󱔗',
            self::Info    => '󰋽',
            self::Success => '󰄬',
        };
    }

    /**
     * Unicode symbol for this type (✖ ⚠ ℹ ✔ — fallback when no NerdFont).
     */
    public function unicodeIcon(): string
    {
        return match ($this) {
            self::Error   => '✖',
            self::Warning => '⚠',
            self::Info    => 'ℹ',
            self::Success => '✔',
        };
    }

    /**
     * Plain ASCII prefix for this type.
     */
    public function asciiPrefix(): string
    {
        return match ($this) {
            self::Error   => '[E]',
            self::Warning => '[W]',
            self::Info    => '[I]',
            self::Success => '[S]',
        };
    }

    /**
     * ANSI color code for this type's icon (SGR).
     *
     * The renderer decodes these slots through sugar-core's canonical table
     * and emits packed 24-bit RGB, so a user's 16-colour theme remap will not
     * restyle toasts — the codes name the family, they are not what reaches
     * the wire.
     */
    public function color(): string
    {
        return match ($this) {
            self::Error   => '31',  // red
            self::Warning => '33',  // yellow
            self::Info    => '34',  // blue
            self::Success => '32',  // green
        };
    }

    /**
     * Icon using the given symbol set.
     */
    public function icon(SymbolSet $set): string
    {
        return match ($set) {
            SymbolSet::NerdFont => $this->nerdIcon(),
            SymbolSet::Unicode  => $this->unicodeIcon(),
            SymbolSet::Ascii    => $this->asciiPrefix(),
        };
    }

    /**
     * Translated label for this toast type (i18n surface).
     */
    public function label(): string
    {
        return Lang::t('type.' . $this->value);
    }
}
