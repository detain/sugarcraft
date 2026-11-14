<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Modal;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\SizedItem;

/**
 * A confirmation modal dialog.
 */
final class ConfirmModal implements Item, SizedItem
{
    public function __construct(
        private readonly string $message,
        private readonly string $confirmLabel = 'Confirm',
        private readonly string $cancelLabel = 'Cancel',
        private readonly bool $isDanger = false,
    ) {}

    private ?int $width = null;
    private ?int $height = null;

    /**
     * Findings sugar-dashboard-069/173 (D-70): the dialog answers the same
     * SizedItem channel layout hosts measure. Framed chrome - and with it a
     * navigable cursor surface - arrives with the composition design (173
     * deferral, disclosed); this ships the sizing contract.
     */
    public function setSize(int $width, int $height): static
    {
        $clone = clone $this;
        if ($width > 0) {
            $clone->width = $width;
        }
        if ($height > 0) {
            $clone->height = $height;
        }
        return $clone;
    }

    /**
     * @return array{0:int,1:int} the box this dialog paints
     */
    public function innerSize(): array
    {
        return [$this->width ?? Width::string($this->naturalLabel()), $this->height ?? 1];
    }

    private function naturalLabel(): string
    {
        $confirm = $this->isDanger ? '⚠ ' . $this->confirmLabel : $this->confirmLabel;
        $result = $this->message . ' [' . $confirm . ']';
        if ($this->cancelLabel !== '') {
            $result .= ' [' . $this->cancelLabel . ']';
        }
        return $result;
    }

    public static function new(string $message): self
    {
        return new self(message: $message);
    }

    public static function danger(string $message): self
    {
        return new self(message: $message, confirmLabel: 'Delete', cancelLabel: 'Cancel', isDanger: true);
    }

    public static function ok(string $message): self
    {
        return new self(message: $message, confirmLabel: 'OK', cancelLabel: '');
    }

    public static function yesNo(string $message): self
    {
        return new self(message: $message, confirmLabel: 'Yes', cancelLabel: 'No');
    }

    public function render(): string
    {
        $result = $this->naturalLabel();
        if ($this->width !== null) {
            $result = Width::truncateAnsi($result, $this->width);
        }
        return $result;
    }
}
