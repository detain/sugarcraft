<?php

declare(strict_types=1);

namespace SugarCraft\Code\Chat\Handlers;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\ItemList\LoadMoreMsg;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Skills\SkillMentions;
use SugarCraft\Code\Host\SessionTabState;
use SugarCraft\Code\Host\SessionSwitch;
use SugarCraft\Code\Tui\ComposerVim;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\SessionPicker;
use SugarCraft\Code\Tui\SessionTabs;
use SugarCraft\Code\Tui\TextSelection;
use SugarCraft\Code\Tui\Components\PaneLabel;
use SugarCraft\Code\Support\SystemClipboard;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Code\Support\ClipboardImage;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Events\MediaProgress;
use SugarCraft\Code\Events\ReasoningDelta;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Media\PreviewSlots;
use SugarCraft\Code\Events\TokenDelta;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Permissions\PermissionFlow;
use SugarCraft\Code\Permissions\PermissionPrompt;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionPromptStage;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Tools\ToolCall as EngineToolCall;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpAuthCommand;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Commands\WebSearchCommand;
use SugarCraft\Code\Tools\BuiltIn\WebSearch;
use SugarCraft\Code\Observability\EventSink;
use SugarCraft\Code\Observability\NullEventSink;
use SugarCraft\Code\Palette\PaletteAction;
use SugarCraft\Code\Palette\PaletteState;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Forms\Vim\VimState;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;
use SugarCraft\Mouse\ZoneClickTracker;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextWindow;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Session\DebouncedTranscriptWriter;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\PromptHistory;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Util\TokenTracker;
use SugarCraft\Code\Util\TokenEstimate;
use SugarCraft\Code\App\BackgroundSessionSpawnedMsg;
use SugarCraft\Code\App\BackgroundSessionStoppedMsg;
use SugarCraft\Code\App\CancelledWorkflowReportMsg;
use SugarCraft\Code\App\CustomCommandExpandedMsg;
use SugarCraft\Code\App\PermissionModeToggledMsg;
use SugarCraft\Code\App\ClipboardImagePastedMsg;
use SugarCraft\Code\App\SideQuestionAnsweredMsg;
use SugarCraft\Code\App\DreamPassCompletedMsg;
use SugarCraft\Code\App\MemoryConsolidatedMsg;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\App\PermissionRequestMsg;
use SugarCraft\Code\App\RuntimeNoticePumpMsg;
use SugarCraft\Code\App\TurnHooksResolvedMsg;
use SugarCraft\Code\App\AgentMessageSentMsg;
use SugarCraft\Code\App\HistoryCompactedMsg;
use SugarCraft\Code\App\McpLoginProgressMsg;
use SugarCraft\Code\App\PromptSuggestionMsg;
use SugarCraft\Code\App\SessionLockRetryMsg;
use SugarCraft\Code\App\StatusLineResultMsg;
use SugarCraft\Code\App\BangShellResultMsg;
use SugarCraft\Code\App\IdleCompactTickMsg;
use SugarCraft\Code\App\PermissionReplyMsg;
use SugarCraft\Code\App\TranscriptFlushMsg;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\App\CancelAgentRunMsg;
use SugarCraft\Code\App\CloseAgentViewMsg;
use SugarCraft\Code\App\StatusLineTickMsg;
use SugarCraft\Code\App\InitialPromptMsg;
use SugarCraft\Code\App\OpenAgentViewMsg;
use SugarCraft\Code\App\SessionTitledMsg;
use SugarCraft\Code\App\ToolEventPumpMsg;
use SugarCraft\Code\App\AgentControlMsg;
use SugarCraft\Code\App\ToolResultsMsg;
use SugarCraft\Code\App\GoalJudgedMsg;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Chat\Handlers\HandlesRunControl;
use SugarCraft\Code\Chat\Handlers\HandlesTurnCompletion;
use SugarCraft\Code\Message;
use SugarCraft\Code\Lang;

// Route() family: session-surface arms (background session notices and
// pump, window reflow, mouse, paste bracket, clipboard image, picker load-more). W7-S4.
trait HandlesSessionSurface
{
    /**
     * Route family handler: owns the class-disjoint Msg arms listed below;
     * returns null when none match so route()'s chain can try the next
     * family (W7-S4 decomposition, behaviour-identical to the old inline
     * dispatch — arms kept verbatim, including their comments).
     */
    private function handleSessionSurfaceMessages(Msg $msg): ?array
    {

        if ($msg instanceof BackgroundSessionSpawnedMsg) {
            // Unlike the title call above this is NOT session-scoped: the
            // user asked for it out loud with a slash command, so the answer
            // belongs in whatever transcript is in front of them now.
            $notice = \SugarCraft\Code\Host\Commands\BackgroundCommand::spawnedNotice($msg);

            // ISS-097: the same verdict the headless driver's row carries,
            // read through the same predicate — two drivers, one source.
            return [$this->mutate(['history' => [...$this->history, Message::assistant($notice)->withUiOnly()->withError(
                \SugarCraft\Code\Host\Commands\BackgroundCommand::isSpawnFailure($msg),
            )]]), null];
        }
        if ($msg instanceof BackgroundSessionStoppedMsg) {
            // Record the settled status as already announced, so the next
            // background poll does not repeat it as "is now stopped".
            $statuses = $this->backgroundStatuses;
            $settled = $this->backgroundSupervisor?->getSession($msg->sessionId);
            if ($settled !== null) {
                $statuses[$msg->sessionId] = $settled->status->value;
            }

            return [$this->mutate([
                'history' => [...$this->history, Message::assistant(self::backgroundStopNotice($msg))->withUiOnly()->withError(
                    \SugarCraft\Code\Host\Commands\BackgroundCommand::isStopFailure($msg),
                )],
                'backgroundStatuses' => $statuses,
            ]), null];
        }
        if ($msg instanceof BackgroundTickMsg) {
            return $this->pumpBackgroundSessions();
        }
        if ($msg instanceof WindowSizeMsg) {
            // The one authoritative size - see the constructor docblock on
            // $rows/$cols for why Renderer must read these instead of
            // querying terminal size itself.
            // A reflow moves every cell, so a highlight measured on the old
            // frame would sit over text it never selected.
            self::$textSelection = null;

            return [$this->mutate(['rows' => $msg->rows, 'cols' => $msg->cols]), null];
        }
        if ($msg instanceof MouseMsg) {
            return $this->handleMouse($msg);
        }
        if ($msg instanceof PasteMsg) {
            // E704. The whole bracketed payload — `InputReader` collects the
            // bytes between `CSI 200~` and `CSI 201~` (only ever emitted once
            // {@see programOptions()} asks for mode 2004) into ONE message, so
            // a multi-line paste is one atomic edit rather than the byte-burst
            // that used to dispatch one submit per line. Inserted AT the caret
            // through TextArea's string seam; the cursor lands at the END of
            // the pasted text, which is the law every other single edit of
            // this box follows. It does not submit and it does not answer a
            // permission prompt: the Enter that follows a paste is the user's
            // own keystroke and submits the WHOLE draft once, through
            // {@see submit()}'s existing trim — content is inserted verbatim,
            // with no pre-trim, because the boundary sanitize upstream
            // (ProgramOptions::$sanitizePaste, default ON) is the one place
            // untrusted bytes get stripped. The companion PasteEndMsg carries
            // no payload and stays dropped below, with every other non-KeyMsg.
            //
            // E744 WS3 routes this through the widget's OWN paste arm so the
            // frame stops owning a private copy of the draft-edit law: the
            // widget replaces a live selection before inserting (E736 5.13),
            // and on an empty selection `deleteSelection()` returns the
            // editor unchanged — making the focused no-selection outcome
            // byte-identical to the insertString call this arm used to make.
            // A dropped/unfocused editor answers its paste with the SAME
            // instance, and the fallback below keeps this box's pre-E744
            // contract for that state verbatim: paste lands regardless.
            //
            // Audit 15b-15: a paste that is nothing but the path of an image
            // file - what dropping a screenshot onto most terminals types -
            // becomes an `@` mention of it instead, so the image is attached
            // when the prompt is sent rather than its path sent as prose.
            // The `/new` folder picker takes a pasted path into its path box.
            if ($this->dirPicker !== null) {
                return [$this->mutate(['dirPicker' => $this->dirPicker->withPaste($msg->content)]), null];
            }
            // The history search takes a paste into its query for the same
            // reason (TUI-2): the overlay owns typing, and the draft behind
            // it must survive the search untouched.
            if ($this->historySearch !== null) {
                return [$this->mutate(['historySearch' => $this->historySearch->withPaste($msg->content)]), null];
            }
            $imagePath = self::pastedImagePath($msg->content);
            if ($imagePath !== null) {
                return [$this->withInput($this->input->insertString($this->mentionFor($imagePath) . ' ')), null];
            }

            // ISS-051: pastes that would bury the box go under a chip. The
            // check sits after the folder picker (a pasted PATH stays a path)
            // and after the image-mention arm (a screenshot path is one
            // short line anyway); everything else that is big gets collapsed.
            if (self::pasteNeedsCollapse($msg->content)) {
                return [$this->withCollapsedPaste($msg->content), null];
            }

            [$pasted] = $this->input->update($msg);
            \assert($pasted instanceof TextArea);
            if ($pasted === $this->input) {
                return [$this->withInput($this->input->insertString($msg->content)), null];
            }

            return [$this->withInput($pasted), null];
        }
        if ($msg instanceof ClipboardImagePastedMsg) {
            // Ctrl+V's answer (audit 15b-15): the saved image goes into the
            // draft as an `@` mention at the caret, exactly as a typed or
            // dropped one would, so it is attached on send and visible - and
            // removable - until then.
            if ($msg->path === null) {
                return [$this->mutate(['history' => [...$this->history, Message::notice(Lang::t('chat.clipboard.no_image'))]]), null];
            }

            return [$this->withInput($this->input->insertString($this->mentionFor($msg->path) . ' ')), null];
        }

        // E744 WS5: the session picker's load-more edge. The ONLY production
        // source of this Msg in crush is sugar-forms ItemList's
        // arrival-on-last-row rule (a keyboard step, wheel notch, or click
        // that moves the cursor onto the last row of the loaded page while
        // more may exist upstream), raised as Cmd::send and relayed back
        // through the frame by the E744 WS1 wrapper. Consumed while the
        // picker is up; with no picker the signal has no owner and joins
        // every other unhandled Msg below.
        if ($msg instanceof LoadMoreMsg) {
            return $this->handleSessionLoadMore();
        }

        return null;
    }
}
