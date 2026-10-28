<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

use SugarCraft\Code\Message;
use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Code\Role;

/**
 * Reads candy-code's own export JSON back into Messages.
 *
 * The producer is {@see \SugarCraft\Code\Util\Exporter::toJson()}: a top
 * level list of wire rows with `role`, `content`, optional `attachments`
 * ({type,path}) and optional `tool_calls` ({name,arguments,id}). Rows
 * carrying tool output add `tool_results`, each the ToolResult wire shape
 * ({role:'tool', tool_call_id, name, content}) plus the export-only
 * `is_error` flag.
 *
 * Because this is OUR format, tool activity round-trips as real tool rows
 * through {@see Message::fromArray()} after the wire→persisted key remap
 * (`tool_calls`→`toolCalls`, `tool_results`→`toolResults`, and the result
 * wire's `content`/`is_error` pair split back into `result`/`error`). The
 * flatten-to-text law applies to FOREIGN transcripts only.
 *
 * Schema pinned against: candy-code's own Exporter at this commit.
 */
final class CandyCodeExportReader
{
    private function __construct()
    {
    }

    /**
     * @return array{messages: list<Message>, title: ?string}
     */
    public static function read(string $raw, SecretRedactor $redactor, int $maxItems): array
    {
        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ImportRefusedException::shape('candy-code export is not valid JSON');
        }

        if (!\is_array($decoded) || !\array_is_list($decoded)) {
            throw ImportRefusedException::shape('candy-code export must be a JSON list of rows');
        }

        if (\count($decoded) > $maxItems) {
            throw ImportRefusedException::tooManyItems($maxItems);
        }

        $messages = [];
        foreach ($decoded as $i => $row) {
            if (!\is_array($row)) {
                throw ImportRefusedException::shape('row ' . $i . ' is not an object');
            }
            $role = $row['role'] ?? null;
            $content = $row['content'] ?? null;
            if (!\is_string($role) || Role::tryFrom($role) === null) {
                throw ImportRefusedException::shape('row ' . $i . ' carries an unknown role value');
            }
            if (!\is_string($content)) {
                throw ImportRefusedException::shape('row ' . $i . ' has no string content');
            }

            $persisted = [
                'role' => $role,
                'content' => $redactor->redact($content),
            ];
            if (isset($row['attachments']) && \is_array($row['attachments'])) {
                $persisted['attachments'] = $row['attachments'];
            }
            if (isset($row['tool_calls']) && \is_array($row['tool_calls'])) {
                $persisted['toolCalls'] = $row['tool_calls'];
            }
            if (isset($row['tool_results'])) {
                if (!\is_array($row['tool_results'])) {
                    throw ImportRefusedException::shape('row ' . $i . ' carries a non-list tool_results value');
                }
                $persisted['toolResults'] = self::remapToolResults($row['tool_results'], $i, $redactor);
            }

            $messages[] = Message::fromArray($persisted);
        }

        return ['messages' => $messages, 'title' => null];
    }

    /**
     * The export's `tool_results` rows (wire shape + `is_error`) back to the
     * persisted shape fromArray() expects, with the result text redacted.
     *
     * @param array<array-key, mixed> $results
     *
     * @return list<array<string, mixed>>
     */
    private static function remapToolResults(array $results, int $rowIndex, SecretRedactor $redactor): array
    {
        $out = [];
        foreach ($results as $result) {
            if (!\is_array($result) || !\is_string($result['name'] ?? null) || !\is_string($result['content'] ?? null)) {
                throw ImportRefusedException::shape('row ' . $rowIndex . ' carries a tool result without a name and content');
            }
            $text = $redactor->redact($result['content']);
            $isError = ($result['is_error'] ?? false) === true;
            $out[] = [
                'name' => $result['name'],
                'result' => $isError ? '' : $text,
                'error' => $isError ? $text : null,
                'id' => \is_string($result['tool_call_id'] ?? null) ? $result['tool_call_id'] : null,
            ];
        }

        return $out;
    }
}
