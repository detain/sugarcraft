<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Code\Lang;

/**
 * Decodes a structured-output schema ARGUMENT — the value of headless
 * `--json-schema` and of a workflow task's `outputSchema` — into the trusted
 * array shape every consumer passes to EngineBackend::withJsonSchema().
 *
 * The raw token is either an INLINE JSON document (leading `{`), a path to a
 * schema file, or (workflow YAML only, via requireSchema) a natively parsed
 * map. A leading `[` is refused as a document-but-not-a-schema rather than
 * misread as a missing file — the fail-loud law that was born here as
 * NonInteractive::decodeJsonSchema() and now lives in ONE copy, shared by the
 * headless CLI and the workflow engine so a stage and a `-p` run interpret
 * the same token identically.
 */
final class JsonSchemaArgument
{
    private function __construct()
    {
    }

    /**
     * @return array<mixed>|null the decoded schema object, or null when no schema was asked for
     *
     * @throws \InvalidArgumentException when the token is not a readable, valid JSON object
     */
    public static function decode(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $text = \trim($raw);
        if (\str_starts_with($text, '[')) {
            // An inline JSON LIST: clearly a document, clearly not a schema.
            // Calling it a missing file — which the path arm would otherwise
            // do — is the confusing half of fail-loud.
            throw new \InvalidArgumentException(Lang::t('cli.noninteractive.json_schema_not_object'));
        }

        try {
            if (\str_starts_with($text, '{')) {
                $decoded = \json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
            } else {
                // Checked before reading: file_get_contents() raises a PHP
                // WARNING on a miss (the suite fails on warnings), and a
                // TOCTOU loss between check and read still lands on the
                // same honest refusal via the false arm.
                if (!\is_file($text) || !\is_readable($text)) {
                    throw new \InvalidArgumentException(Lang::t('cli.noninteractive.json_schema_unreadable', ['path' => $text]));
                }

                $contents = \file_get_contents($text);
                if ($contents === false) {
                    throw new \InvalidArgumentException(Lang::t('cli.noninteractive.json_schema_unreadable', ['path' => $text]));
                }

                $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            }
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(Lang::t('cli.noninteractive.json_schema_invalid', ['error' => $e->getMessage()]), 0, $e);
        }

        if (!\is_array($decoded) || (\array_is_list($decoded) && $decoded !== [])) {
            throw new \InvalidArgumentException(Lang::t('cli.noninteractive.json_schema_not_object'));
        }

        return $decoded;
    }
}
