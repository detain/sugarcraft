<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Color;

final class FlowchartNode
{
    /** @var list<string> */
    public array $nextIds = [];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly FlowchartNodeType $type = FlowchartNodeType::Process,
        public readonly ?Color $color = null,
    ) {}

    public function withNext(string $nextId): self
    {
        $clone = clone $this;
        $clone->nextIds[] = $nextId;
        return $clone;
    }

    public static function process(string $id, string $label): self
    {
        return new self($id, $label, FlowchartNodeType::Process);
    }

    public static function decision(string $id, string $label): self
    {
        return new self($id, $label, FlowchartNodeType::Decision);
    }

    public static function startEnd(string $id, string $label): self
    {
        return new self($id, $label, FlowchartNodeType::StartEnd);
    }

    public static function inputOutput(string $id, string $label): self
    {
        return new self($id, $label, FlowchartNodeType::InputOutput);
    }
}
