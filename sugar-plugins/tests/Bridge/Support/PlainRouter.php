<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge\Support;

use SugarCraft\Plugins\Bridge\ConversationRouterCore;

/**
 * A concrete router for the core suite. The core declares channelFaces() and
 * commandResultText() without a default (each bundle owns its copy), so the
 * suite supplies the same two bodies the bundles carry.
 */
class PlainRouter extends ConversationRouterCore
{
    protected function channelFaces(): array
    {
        $seen = [];
        foreach ($this->channels as $face) {
            $seen[\spl_object_id($face)] = $face;
        }

        return \array_values($seen);
    }

    protected function commandResultText(array $result): string
    {
        $parts = [];
        foreach ((array) ($result['rows'] ?? []) as $row) {
            if (!\is_array($row) || (bool) ($row['uiOnly'] ?? false)) {
                continue;
            }
            $content = \trim((string) ($row['content'] ?? ''));
            if ($content !== '') {
                $parts[] = $content;
            }
        }
        $text = \implode("\n\n", $parts);

        return $text === '' ? 'Done.' : $text;
    }
}
