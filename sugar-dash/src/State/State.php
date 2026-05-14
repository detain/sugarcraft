<?php

declare(strict_types=1);

namespace SugarCraft\Dash\State;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * State machine transition types.
 */
enum TransitionType: string
{
    case Normal = 'normal';
    case Guard = 'guard';
    case Internal = 'internal';
}

/**
 * A state in a state machine diagram.
 */
final class StateNode
{
    /** @var list<string> */
    public array $entryActions = [];

    /** @var list<string> */
    public array $exitActions = [];

    /** @var list<string> */
    public array $internalActions = [];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly bool $isInitial = false,
        public readonly bool $isFinal = false,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Add an entry action.
     */
    public function withEntry(string $action): self
    {
        $clone = clone $this;
        $clone->entryActions[] = $action;
        return $clone;
    }

    /**
     * Add an exit action.
     */
    public function withExit(string $action): self
    {
        $clone = clone $this;
        $clone->exitActions[] = $action;
        return $clone;
    }

    /**
     * Add an internal action.
     */
    public function withInternal(string $action): self
    {
        $clone = clone $this;
        $clone->internalActions[] = $action;
        return $clone;
    }

    /**
     * Create an initial state.
     */
    public static function initial(string $id, string $label): self
    {
        return new self($id, $label, true, false);
    }

    /**
     * Create a final state.
     */
    public static function final(string $id, string $label): self
    {
        return new self($id, $label, false, true);
    }

    /**
     * Create a normal state.
     */
    public static function state(string $id, string $label): self
    {
        return new self($id, $label, false, false);
    }
}

/**
 * A transition between states in a state machine diagram.
 */
final class StateTransition
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly TransitionType $type = TransitionType::Normal,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a guard transition (conditional).
     */
    public static function guard(string $id, string $from, string $to, string $label): self
    {
        return new self($id, $from, $to, $label, TransitionType::Guard);
    }
}

/**
 * A state machine diagram component for visualizing system states and transitions.
 *
 * Features:
 * - Initial, normal, and final states
 * - Transitions with labels and guard conditions
 * - Entry/exit/internal actions
 * - Compound states (hierarchical)
 * - Choice pseudo-states
 *
 * Mirrors UML state machine diagram patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class State implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var array<string, StateNode> */
    private array $states = [];

    /** @var list<StateTransition> */
    private array $transitions = [];

    private string $initialStateId = '';
    private bool $showActions = true;
    private bool $showLabels = true;
    private string $style = 'rounded';

    public function __construct(
        private ?Color $stateColor = null,
        private ?Color $initialColor = null,
        private ?Color $finalColor = null,
        private ?Color $transitionColor = null,
        private ?Color $textColor = null,
        private string $style_ = 'rounded',
    ) {}

    /**
     * Create a new state machine diagram with default styling.
     */
    public static function new(): self
    {
        return new self(
            stateColor: Color::hex('#89B4FA'),
            initialColor: Color::hex('#A6E3A1'),
            finalColor: Color::hex('#F38BA8'),
            transitionColor: Color::hex('#CBA6F7'),
            textColor: Color::hex('#CDD6F4'),
            style_: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this state diagram.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a state to the diagram.
     */
    public function withState(StateNode $state): self
    {
        $clone = clone $this;
        $clone->states[$state->id] = $state;
        if ($state->isInitial && $clone->initialStateId === '') {
            $clone->initialStateId = $state->id;
        }
        return $clone;
    }

    /**
     * Add a state by parameters.
     */
    public function addState(string $id, string $label, bool $isInitial = false, bool $isFinal = false): self
    {
        $state = new StateNode($id, $label, $isInitial, $isFinal);
        return $this->withState($state);
    }

    /**
     * Set all states at once.
     *
     * @param array<string, StateNode> $states
     */
    public function withStates(array $states): self
    {
        $clone = clone $this;
        $clone->states = $states;
        return $clone;
    }

    /**
     * Add a transition to the diagram.
     */
    public function withTransition(StateTransition $transition): self
    {
        $clone = clone $this;
        $clone->transitions[] = $transition;
        return $clone;
    }

    /**
     * Add a transition by parameters.
     */
    public function addTransition(string $from, string $to, string $label): self
    {
        return $this->withTransition(new StateTransition(
            uniqid('', true),
            $from,
            $to,
            $label,
        ));
    }

    /**
     * Add a guard transition.
     */
    public function addGuard(string $from, string $to, string $condition): self
    {
        return $this->withTransition(StateTransition::guard(
            uniqid('', true),
            $from,
            $to,
            '[' . $condition . ']',
        ));
    }

    /**
     * Set all transitions at once.
     *
     * @param list<StateTransition> $transitions
     */
    public function withTransitions(array $transitions): self
    {
        $clone = clone $this;
        $clone->transitions = $transitions;
        return $clone;
    }

    /**
     * Set the initial state ID.
     */
    public function withInitialState(string $stateId): self
    {
        $clone = clone $this;
        $clone->initialStateId = $stateId;
        return $clone;
    }

    /**
     * Show or hide actions.
     */
    public function withShowActions(bool $show): self
    {
        $clone = clone $this;
        $clone->showActions = $show;
        return $clone;
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        $clone = clone $this;
        $clone->showLabels = $show;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }

    /**
     * Render the state machine diagram as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 20 || $useHeight < 8) {
            return '';
        }

        return $this->renderDiagram($useWidth, $useHeight);
    }

    /**
     * Render the complete state diagram.
     */
    private function renderDiagram(int $width, int $height): string
    {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Title
        $title = 'State Machine';
        $titleX = intval(($width - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titleX - 1) . $title . str_repeat('─', $width - 2 - $titleX - strlen($title)) . $tr . "\n";

        // Calculate state positions (simple grid layout)
        $stateCount = count($this->states);
        $cols = max(1, intval(sqrt(max(1, $stateCount))));
        $rows = max(1, intval(($stateCount + $cols - 1) / $cols));
        $cellWidth = intval(($width - 4) / $cols);
        $cellHeight = intval(($height - 4) / $rows);

        $statePositions = [];
        $index = 0;

        foreach ($this->states as $stateId => $state) {
            $col = $index % $cols;
            $row = intval($index / $cols);

            $x = 2 + $col * $cellWidth + intval($cellWidth / 2);
            $y = 2 + $row * $cellHeight + intval($cellHeight / 2);

            $statePositions[$stateId] = [
                'state' => $state,
                'x' => $x,
                'y' => $y,
            ];
            $index++;
        }

        // Draw states
        $chartHeight = $height - 4;
        $chartWidth = $width - 2;

        // Draw horizontal grid lines
        for ($r = 0; $r <= $rows; $r++) {
            $y = 2 + $r * $cellHeight;
            if ($y < $height - 1) {
                $line = '';
                for ($x = 0; $x < $chartWidth; $x++) {
                    $line .= '─';
                }
                $result .= $v . $line . $v . "\n";
            }
        }

        // Render states in cells
        $index = 0;
        foreach ($this->states as $stateId => $state) {
            $col = $index % $cols;
            $row = intval($index / $cols);

            $cellX = 2 + $col * $cellWidth;
            $cellY = 2 + $row * $cellHeight;
            $stateWidth = min($cellWidth - 2, 12);
            $stateHeight = min($cellHeight - 2, 4);

            $stateLine = $this->renderStateInCell($state, $stateWidth, $stateHeight);
            $result .= $v . str_pad($stateLine, $chartWidth) . $v . "\n";

            $index++;
        }

        // Draw transitions between states
        if ($this->showLabels && !empty($this->transitions)) {
            foreach ($this->transitions as $transition) {
                $fromPos = $statePositions[$transition->from] ?? null;
                $toPos = $statePositions[$transition->to] ?? null;

                if ($fromPos !== null && $toPos !== null) {
                    $transitionColor = $transition->color ?? $this->transitionColor ?? Color::hex('#CBA6F7');
                    $arrow = '';
                    if ($transitionColor !== null) {
                        $arrow .= $transitionColor->toFg(ColorProfile::TrueColor);
                    }
                    $arrow .= ' ──' . $transition->label . '── ';
                    if ($transitionColor !== null) {
                        $arrow .= Ansi::reset();
                    }
                    $result .= $arrow;
                }
            }
        }

        // Bottom border
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
    }

    /**
     * Render a state within a cell.
     */
    private function renderStateInCell(StateNode $state, int $width, int $height): string
    {
        if ($width < 4 || $height < 2) {
            return mb_substr($state->label, 0, $width);
        }

        $stateColor = $state->color ?? match (true) {
            $state->isInitial => $this->initialColor ?? Color::hex('#A6E3A1'),
            $state->isFinal => $this->finalColor ?? Color::hex('#F38BA8'),
            default => $this->stateColor ?? Color::hex('#89B4FA'),
        };

        // Different box styles for different state types
        $label = $state->label;
        $innerWidth = $width - 2;

        $result = '';
        if ($state->isInitial) {
            // Filled circle for initial state
            $result .= '●' . str_pad($label, $innerWidth);
        } elseif ($state->isFinal) {
            // Double circle for final state
            $result .= '◉' . str_pad($label, $innerWidth);
        } else {
            // Rounded rectangle for normal state
            $result .= '╭' . str_repeat('─', $innerWidth) . '╮';
        }

        // Entry/exit actions if shown
        if ($this->showActions && !empty($state->entryActions)) {
            $actionsLine = '│entry/' . implode(',', $state->entryActions);
            $result .= "\n" . str_pad($actionsLine, $width);
        }

        return mb_substr($result, 0, $width);
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this state diagram.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $stateCount = count($this->states);
        $cols = max(1, intval(sqrt($stateCount)));
        $rows = intval(($stateCount + $cols - 1) / $cols);

        $width = $this->width ?? max(30, $cols * 15);
        $height = $this->height ?? max(10, $rows * 6 + 4);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the state color.
     */
    public function withStateColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->stateColor = $color;
        return $clone;
    }

    /**
     * Set the initial state color.
     */
    public function withInitialColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->initialColor = $color;
        return $clone;
    }

    /**
     * Set the final state color.
     */
    public function withFinalColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->finalColor = $color;
        return $clone;
    }

    /**
     * Set the transition color.
     */
    public function withTransitionColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->transitionColor = $color;
        return $clone;
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->textColor = $color;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withBorderStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }
}
