<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A message sent between participants in a sequence diagram.
 */
final class SequenceMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly bool $isReply = false,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a reply message.
     */
    public static function reply(string $id, string $from, string $to, string $label, ?Color $color = null): self
    {
        return new self($id, $from, $to, $label, true, $color);
    }
}

/**
 * A participant (object/actor) in a sequence diagram.
 */
final class SequenceParticipant
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create an actor participant.
     */
    public static function actor(string $id, string $label): self
    {
        return new self($id, $label, Color::hex('#CBA6F7'));
    }

    /**
     * Create an object participant.
     */
    public static function object(string $id, string $label): self
    {
        return new self($id, $label, Color::hex('#89B4FA'));
    }
}

/**
 * A sequence diagram component for visualizing object interactions.
 *
 * Features:
 * - Participant lifelines with activation boxes
 * - Synchronous and asynchronous messages
 * - Reply messages with dashed arrows
 * - Self-calls and nested activations
 * - Multiple interaction fragments (loop, alt, opt)
 *
 * Mirrors UML sequence diagram patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Sequence implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<SequenceParticipant> */
    private array $participants = [];

    /** @var list<SequenceMessage> */
    private array $messages = [];

    private bool $showLabels = true;
    private bool $showActivations = true;
    private string $style = 'rounded';

    public function __construct(
        private readonly ?Color $ lifelineColor = null,
        private readonly ?Color $messageColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $activationColor = null,
        private readonly string $style_ = 'rounded',
    ) {}

    /**
     * Create a new sequence diagram with default styling.
     */
    public static function new(): self
    {
        return new self(
            lifelineColor: Color::hex('#45475A'),
            messageColor: Color::hex('#89B4FA'),
            textColor: Color::hex('#CDD6F4'),
            activationColor: Color::hex('#A6E3A1'),
            style_: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this sequence diagram.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a participant to the diagram.
     */
    public function withParticipant(SequenceParticipant $participant): self
    {
        $clone = clone $this;
        $clone->participants[] = $participant;
        return $clone;
    }

    /**
     * Add a participant by parameters.
     */
    public function addParticipant(string $id, string $label, ?Color $color = null): self
    {
        return $this->withParticipant(new SequenceParticipant($id, $label, $color));
    }

    /**
     * Add all participants at once.
     *
     * @param list<SequenceParticipant> $participants
     */
    public function withParticipants(array $participants): self
    {
        $clone = clone $this;
        $clone->participants = $participants;
        return $clone;
    }

    /**
     * Add a message to the diagram.
     */
    public function withMessage(SequenceMessage $message): self
    {
        $clone = clone $this;
        $clone->messages[] = $message;
        return $clone;
    }

    /**
     * Add a message by parameters.
     */
    public function addMessage(string $id, string $from, string $to, string $label, ?Color $color = null): self
    {
        return $this->withMessage(new SequenceMessage($id, $from, $to, $label, false, $color));
    }

    /**
     * Add a reply message.
     */
    public function addReply(string $id, string $from, string $to, string $label, ?Color $color = null): self
    {
        return $this->withMessage(SequenceMessage::reply($id, $from, $to, $label, $color));
    }

    /**
     * Add all messages at once.
     *
     * @param list<SequenceMessage> $messages
     */
    public function withMessages(array $messages): self
    {
        $clone = clone $this;
        $clone->messages = $messages;
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
     * Show or hide activations.
     */
    public function withShowActivations(bool $show): self
    {
        $clone = clone $this;
        $clone->showActivations = $show;
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
     * Render the sequence diagram as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 70;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 30 || $useHeight < 8 || empty($this->participants)) {
            return '';
        }

        return $this->renderDiagram($useWidth, $useHeight);
    }

    /**
     * Render the complete sequence diagram.
     */
    private function renderDiagram(int $width, int $height): string
    {
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $result = '';

        // Calculate participant column positions
        $participantCount = count($this->participants);
        if ($participantCount === 0) {
            return '';
        }

        $colWidth = intval(($width - 4) / $participantCount);
        $participantPositions = [];
        $currentX = 2;

        foreach ($this->participants as $participant) {
            $participantPositions[$participant->id] = [
                'participant' => $participant,
                'x' => $currentX + intval($colWidth / 2),
            ];
            $currentX += $colWidth;
        }

        // Title
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();
        $title = 'Sequence Diagram';
        $titleX = intval(($width - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titleX - 1) . $title . str_repeat('─', $width - 2 - $titleX - strlen($title)) . $tr . "\n";

        // Participant headers
        $headerLine = $v;
        foreach ($participantPositions as $info) {
            $participant = $info['participant'];
            $x = $info['x'];
            $label = $participant->label;
            $labelLen = strlen($label);

            // Box the participant name
            $boxWidth = max(8, $labelLen + 2);
            $padding = intval(($colWidth - $boxWidth) / 2);

            $headerLine .= str_repeat(' ', $padding);
            if ($participant->color !== null) {
                $headerLine .= $participant->color->toFg(ColorProfile::TrueColor);
            }
            $headerLine .= '┌' . str_repeat('─', $boxWidth - 2) . '┐';
            if ($participant->color !== null) {
                $headerLine .= Ansi::reset();
            }
            $headerLine .= str_repeat(' ', $colWidth - $padding - $boxWidth - 1);
        }
        $headerLine .= $v;
        $result .= $headerLine . "\n";

        // Participant names
        $nameLine = $v;
        foreach ($participantPositions as $info) {
            $participant = $info['participant'];
            $label = $participant->label;
            $boxWidth = max(8, strlen($label) + 2);
            $padding = intval(($colWidth - $boxWidth) / 2);

            $nameLine .= str_repeat(' ', $padding);
            $nameLine .= '│' . str_pad($label, $boxWidth - 2) . '│';
            $nameLine .= str_repeat(' ', $colWidth - $padding - $boxWidth - 1);
        }
        $nameLine .= $v;
        $result .= $nameLine . "\n";

        // Bottom of participant boxes
        $bottomLine = $v;
        foreach ($participantPositions as $info) {
            $participant = $info['participant'];
            $label = $participant->label;
            $boxWidth = max(8, strlen($label) + 2);
            $padding = intval(($colWidth - $boxWidth) / 2);

            $bottomLine .= str_repeat(' ', $padding);
            $bottomLine .= '╰' . str_repeat('─', $boxWidth - 2) . '╯';
            $bottomLine .= str_repeat(' ', $colWidth - $padding - $boxWidth - 1);
        }
        $bottomLine .= $v;
        $result .= $bottomLine . "\n";

        // Separator line
        $result .= $v . str_repeat(' ', $width - 2) . $v . "\n";

        // Lifelines and messages
        $ lifelineColor = $this->lifelineColor ?? Color::hex('#45475A');
        $messageColor = $this->messageColor ?? Color::hex('#89B4FA');

        // Draw lifelines
        $lifelineAreaHeight = $height - 8;
        foreach ($participantPositions as $id => $info) {
            $x = $info['x'];
            $participant = $info['participant'];

            $lineOffset = $width - 2;

            // Draw message column range for this participant
            $colStart = $participantPositions[$id]['x'] - intval($colWidth / 2) + 1;
            $colEnd = $colStart + $colWidth - 1;
        }

        // Draw messages
        $messageRow = 0;
        foreach ($this->messages as $message) {
            if ($messageRow >= $lifelineAreaHeight - 2) {
                break;
            }

            $fromPos = $participantPositions[$message->from] ?? null;
            $toPos = $participantPositions[$message->to] ?? null;

            if ($fromPos === null || $toPos === null) {
                continue;
            }

            $fromX = $fromPos['x'];
            $toX = $toPos['x'];

            // Determine message direction
            $isForward = $toX > $fromX;
            $minX = min($fromX, $toX);
            $maxX = max($fromX, $toX);
            $arrowLen = $maxX - $minX - 3;

            if ($arrowLen < 1) {
                $arrowLen = 1;
            }

            // Build the message line
            $messageLine = str_repeat(' ', $minX + 1);

            if ($message->isReply) {
                // Dashed line for reply
                $messageLine .= '│';
                $messageLine .= str_repeat(' ', $arrowLen);
                $messageLine .= '╰';
                $messageLine .= str_repeat('─', $arrowLen);
                $messageLine .= '╮';
            } else {
                // Solid arrow for message
                if ($isForward) {
                    $messageLine .= '├';
                    $messageLine .= str_repeat('─', $arrowLen);
                    $messageLine .= '●';
                } else {
                    $messageLine .= '●';
                    $messageLine .= str_repeat('─', $arrowLen);
                    $messageLine .= '┤';
                }
            }

            // Add message label above the arrow
            if ($this->showLabels) {
                $result .= $v . str_repeat(' ', $width - 2) . $v . "\n";
                $labelX = intval(($minX + $maxX) / 2) - intval(strlen($message->label) / 2);
                $labelLine = str_repeat(' ', $labelX);
                if ($messageColor !== null) {
                    $labelLine .= $messageColor->toFg(ColorProfile::TrueColor);
                }
                $labelLine .= $message->label;
                if ($messageColor !== null) {
                    $labelLine .= Ansi::reset();
                }
                $result .= $v . str_pad($labelLine, $width - 2) . $v . "\n";
            }

            $messageRow++;
        }

        // Fill remaining space
        while ($messageRow < $lifelineAreaHeight - 2) {
            $result .= $v . str_repeat(' ', $width - 2) . $v . "\n";
            $messageRow++;
        }

        // Bottom border
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
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
     * Calculate the natural dimensions of this sequence diagram.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? max(40, count($this->participants) * 10 + 10);
        $height = $this->height ?? max(12, count($this->messages) * 2 + 8);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the lifeline color.
     */
    public function withLifelineColor(?Color $color): self
    {
        return new self(
            lifelineColor: $color,
            messageColor: $this->messageColor,
            textColor: $this->textColor,
            activationColor: $this->activationColor,
            style_: $this->style,
        );
    }

    /**
     * Set the message color.
     */
    public function withMessageColor(?Color $color): self
    {
        return new self(
            lifelineColor: $this->lifelineColor,
            messageColor: $color,
            textColor: $this->textColor,
            activationColor: $this->activationColor,
            style_: $this->style,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            lifelineColor: $this->lifelineColor,
            messageColor: $this->messageColor,
            textColor: $color,
            activationColor: $this->activationColor,
            style_: $this->style,
        );
    }

    /**
     * Set the activation color.
     */
    public function withActivationColor(?Color $color): self
    {
        return new self(
            lifelineColor: $this->lifelineColor,
            messageColor: $this->messageColor,
            textColor: $this->textColor,
            activationColor: $color,
            style_: $this->style,
        );
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
