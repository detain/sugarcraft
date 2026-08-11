<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Palette;

use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;

/**
 * The actions the Ctrl+P command palette's "root" mode can dispatch. {@see
 * \SugarCraft\Crush\Chat::runSelectedPaletteAction()} dispatches on these;
 * SwitchModel/SwitchTheme transition the palette into a second-level list
 * (provider names / theme names) rather than running an action directly.
 *
 * This enum is a dispatch key only - it no longer owns an item list or any
 * display text. Both live on the matching {@see CommandSpec} row in {@see
 * CommandRegistry::all()}, the single registry the "/" popup reads too, so
 * the two surfaces cannot drift apart the way they did when each kept its
 * own hand-written list.
 */
enum PaletteAction: string
{
    case NewSession = 'new_session';
    case SwitchSession = 'switch_session';
    case SwitchModel = 'switch_model';
    case ShareSession = 'share_session';
    case OpenDocs = 'open_docs';
    case Exit = 'exit';
    case SwitchTheme = 'switch_theme';
    case SwitchAgent = 'switch_agent';
    case ToggleMcp = 'toggle_mcp';

    /**
     * This action's registry row. Throws rather than returning null: an
     * action with no row would be listed by nothing and dispatchable by
     * nothing, which is a wiring bug, not a runtime condition.
     */
    public function spec(): CommandSpec
    {
        $spec = CommandRegistry::forPaletteAction($this);
        if ($spec === null) {
            throw new \LogicException(sprintf(
                'PaletteAction::%s has no CommandRegistry row - add one to CommandRegistry::all().',
                $this->name,
            ));
        }

        return $spec;
    }

    /** The palette row's display text. */
    public function label(): string
    {
        return $this->spec()->label();
    }

    /** The palette row's grouping label, e.g. "Session". */
    public function category(): string
    {
        return $this->spec()->category;
    }

    /** The keybind that also triggers this action, if any. */
    public function shortcut(): ?string
    {
        return $this->spec()->shortcut;
    }

    /**
     * The palette's root item list, derived from {@see CommandRegistry} in
     * its declared order - NOT from self::cases(), so adding a palette entry
     * means adding a registry row, and the "/" popup sees it too.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $actions = [];
        foreach (CommandRegistry::paletteEntries() as $spec) {
            $actions[] = $spec->paletteAction;
        }

        return $actions;
    }

    public static function byLabel(string $label): ?self
    {
        foreach (self::all() as $action) {
            if ($action->label() === $label) {
                return $action;
            }
        }

        return null;
    }
}
