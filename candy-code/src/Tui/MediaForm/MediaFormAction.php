<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

use SugarCraft\Code\Media\MediaRequest;

/**
 * The action object the shell consumes: kind plus, for the kinds that
 * decide values, the final {@see MediaRequest}.
 */
final class MediaFormAction
{
    private function __construct(
        public readonly MediaFormActionKind $kind,
        public readonly ?MediaRequest $request,
    ) {
    }

    public static function cancel(): self
    {
        return new self(MediaFormActionKind::Cancel, null);
    }

    public static function generate(MediaRequest $request): self
    {
        return new self(MediaFormActionKind::Generate, $request);
    }

    public static function savePreset(MediaRequest $request): self
    {
        return new self(MediaFormActionKind::SavePreset, $request);
    }

    public static function importPng(): self
    {
        return new self(MediaFormActionKind::ImportPng, null);
    }
}
