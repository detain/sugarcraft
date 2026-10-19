<?php

declare(strict_types=1);

namespace SugarCraft\Code\Palette;

use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;

/**
 * The actions the Ctrl+P command palette's "root" mode can dispatch. {@see
 * \SugarCraft\Code\Chat::runSelectedPaletteAction()} dispatches on these;
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
    // `/new`'s folder picker: a new session in a directory chosen first.
    case NewSessionPicker = 'new_session_picker';
    case SwitchSession = 'switch_session';
    case SwitchModel = 'switch_model';
    case ShareSession = 'share_session';
    case OpenDocs = 'open_docs';
    case Exit = 'exit';
    case SwitchTheme = 'switch_theme';
    case SwitchAgent = 'switch_agent';
    case ToggleMcp = 'toggle_mcp';
    case DockPaneLeft = 'dock_pane_left';
    case DockPaneRight = 'dock_pane_right';
    case LayoutReset = 'layout_reset';
    case OpenSettings = 'open_settings';
    /** Opens the image generation form (crush-media W3.10, {@see \SugarCraft\Code\App\App::$mediaForm}). */
    case OpenMediaForm = 'open_media_form';
    // P-A4: the session row actions, reachable without opening the list.
    case RenameSession = 'rename_session';
    case PinSession = 'pin_session';
    case DeleteSession = 'delete_session';
    case BranchSession = 'branch_session';

    // VIS-4: the palette became a superset of the slash commands — every
    // registry row whose BARE '/name' form does something meaningful lists
    // here too. These cases exist to make their rows visible to the palette
    // ({@see CommandRegistry::paletteEntries()}); their dispatch is generic
    // ({@see \SugarCraft\Code\Chat::dispatchPaletteCommand()}), so no handler
    // name lives on any of them. The case VALUES are wire-stable ids like the
    // older ones' — none may be renamed, and rows whose bare form only prints
    // usage stayed slash-only with the judgement recorded in their spec file.
    case ShowKeys = 'show_keys';
    case ShowHelp = 'show_help';
    case ShowPermissions = 'show_permissions';
    case ShowNotices = 'show_notices';
    case ShowContext = 'show_context';
    case RunSweep = 'run_sweep';
    case ShowPruning = 'show_pruning';
    case CompressNow = 'compress_now';
    case SendCompressed = 'send_compressed';
    case RestoreSummary = 'restore_summary';
    case InspectHooks = 'inspect_hooks';
    case ListRules = 'list_rules';
    case DraftRule = 'draft_rule';
    case OpenEditor = 'open_editor';
    case CompactNow = 'compact_now';
    case ClearTranscript = 'clear_transcript';
    case ShowBudget = 'show_budget';
    case ShowWorkflows = 'show_workflows';
    case BrowseMemory = 'browse_memory';
    case ListSkills = 'list_skills';
    case InitializeProject = 'initialize_project';
    case RewindSession = 'rewind_session';
    case UndoTurn = 'undo_turn';
    case RedoTurn = 'redo_turn';
    case ShowDiff = 'show_diff';
    case GoalStatus = 'goal_status';
    case GrindStatus = 'grind_status';
    case HandOffSession = 'hand_off_session';
    case ShowRenderMode = 'show_render_mode';

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
