<?php

declare(strict_types=1);

namespace SugarCraft\Code\Notebook;

/**
 * Cell pages — how `Read` answers a notebook (roadmap C-TOOL-6.b).
 *
 * The JSON of an `.ipynb` is a transport, not content: paging it as text
 * hands the model escaped source lines inside base64 haystacks. This
 * renderer turns a {@see Notebook} into numbered cell pages instead — a
 * header per cell, the source verbatim, and for outputs a DIGEST ONLY
 * (`stream 140 chars`, `image/png 18 KB`, `error: ZeroDivisionError`):
 * output bytes never ride the page, because the base64 of a figure is the
 * bulk of a real notebook and none of it is ever what the model needs to
 * decide its next edit.
 *
 * `offset`/`limit` count CELLS, matching what `Read` documents for
 * notebooks; a window that stops short of the last cell says so with the
 * house continuation footer, in cells.
 *
 * The id in a header is written FULL, not elided: the header is where the
 * model learns the `cell_id` to pass back to `NotebookEdit`, and a
 * truncated id is not addressable. (The design's example header shows an
 * elided id; copy-pasteability wins — recorded deviation.)
 */
final class NotebookPages
{
    /** Mime types shown by name in a data digest, most useful first. */
    private const PREFERRED_DATA = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'text/html', 'text/latex'];

    private function __construct() {}

    /**
     * The page for cells $offset..$offset+$limit-1 (1-based, inclusive
     * window semantics identical to `Read`'s line window), or null when
     * $offset is past the last cell — the caller falls through to its own
     * offset refusal wording rather than double-implementing it here.
     */
    public static function render(Notebook $notebook, int $offset, int $limit): ?string
    {
        $cells = $notebook->describe();
        $total = count($cells);
        if ($offset > $total + 1 || $offset < 1 || $limit < 1) {
            return null;
        }

        $first = $offset - 1;
        $last = min($first + $limit, $total);
        $out = [];
        for ($i = $first; $i < $last; $i++) {
            $cell = $cells[$i];
            $header = '[cell ' . ($i + 1) . ' · ' . $cell['type'];
            if ($cell['id'] !== null) {
                $header .= ' · id ' . $cell['id'];
            }

            $header .= $cell['execution_count'] !== null ? ' · ran ' . $cell['execution_count'] : '';
            $out[] = $header . ']';

            $source = rtrim($cell['source'], "\n");
            $out[] = $source === '' ? '(empty)' : $source;

            if ($cell['type'] === 'code' && $cell['outputs'] !== []) {
                $out[] = '  outputs:';
                foreach ($cell['outputs'] as $output) {
                    $digest = self::digest($output);
                    if ($digest !== null) {
                        $out[] = '  - ' . $digest;
                    }
                }
            }
        }

        $page = implode("\n", $out);
        if ($last < $total) {
            $page .= "\n[cells " . ($first + 1) . '-' . $last . ' of ' . $total
                . ' — call Read with offset=' . ($last + 1) . ' to continue]';
        }

        return $page;
    }

    /**
     * One-line digest of a single output, or null for an output shape this
     * renderer does not recognise (never for an error: an error with no
     * ename still lands as `error: <unknown>` rather than silence).
     */
    private static function digest(mixed $output): ?string
    {
        if (!$output instanceof \stdClass || !property_exists($output, 'output_type')
            || !is_string($output->output_type)) {
            return null;
        }

        return match ($output->output_type) {
            'stream' => 'stream ' . self::chars(self::streamText($output)) . ' chars',
            'error' => 'error: ' . (property_exists($output, 'ename') && is_string($output->ename)
                && $output->ename !== '' ? $output->ename : '<unknown>'),
            'display_data', 'execute_result' => self::dataDigest($output),
            default => $output->output_type,
        };
    }

    /** The joined text of a stream output. */
    private static function streamText(\stdClass $output): string
    {
        $text = $output->text ?? null;
        if (is_string($text)) {
            return $text;
        }
        if (is_array($text)) {
            return implode('', array_filter($text, 'is_string'));
        }

        return '';
    }

    /** The dominant payload of a data output: best mime and its decoded size. */
    private static function dataDigest(\stdClass $output): ?string
    {
        $data = property_exists($output, 'data') && $output->data instanceof \stdClass
            ? get_object_vars($output->data)
            : [];
        if ($data === []) {
            return $output->output_type;
        }

        $text = $output->text ?? null;
        if (is_string($text)) {
            return $output->output_type . ' ' . self::bytes(strlen($text));
        }
        if (is_array($text)) {
            return $output->output_type . ' ' . self::bytes(strlen(implode('', array_filter($text, 'is_string'))));
        }

        // Execution-count-free display results carry only data; name the
        // most useful representation rather than every one of them.
        $mime = null;
        foreach (self::PREFERRED_DATA as $candidate) {
            if (isset($data[$candidate])) {
                $mime = $candidate;
                break;
            }
        }

        if ($mime === null && isset($data['text/plain'])) {
            $mime = 'text/plain';
        }

        if ($mime === null) {
            $keys = array_keys($data);
            $mime = is_string($keys[0] ?? null) ? $keys[0] : null;
        }

        if ($mime === null) {
            return $output->output_type;
        }

        $value = $data[$mime];
        if (is_array($value)) {
            $value = implode('', array_filter($value, 'is_string'));
        }

        if (!is_string($value)) {
            return $mime;
        }

        // base64 payloads (images) shrink to their decoded size; text mimes
        // report their characters.
        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'application/')) {
            return $mime . ' ' . self::bytes(self::decodedLength($value));
        }

        return $mime . ' ' . self::chars($value) . ' chars';
    }

    /** Decoded byte length of a base64 string, without holding the bytes. */
    private static function decodedLength(string $base64): int
    {
        $clean = preg_replace('/\s+/', '', $base64) ?? $base64;
        $padding = substr($clean, -2) === '==' ? 2 : (substr($clean, -1) === '=' ? 1 : 0);

        return max(0, (int) (strlen($clean) * 3 / 4) - $padding);
    }

    /** Codepoint count (the "chars" a stream digest quotes). */
    private static function chars(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /** Byte size spelled for a page: KB above a kibibyte, plain B below. */
    private static function bytes(int $count): string
    {
        if ($count >= 1024) {
            return (string) (int) round($count / 1024) . ' KB';
        }

        return $count . ' B';
    }
}
