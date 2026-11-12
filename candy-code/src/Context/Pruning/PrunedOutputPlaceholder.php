<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context\Pruning;

/**
 * The text a pruned tool result is replaced with (roadmap 2.2-1, DCP §13.2 C
 * step 3): one line naming the tool and its main argument, so the model still
 * knows WHAT it looked at and can re-run exactly that call if it needs the
 * output again —
 *
 *     [Read src/Tools/Bash.php — output pruned to save context; re-run the tool if you need it]
 *
 * Pure: the same call always yields the same bytes, which is what keeps a
 * projection byte-stable across requests (the prompt-cache contract).
 */
final class PrunedOutputPlaceholder
{
    /**
     * The argument names that say what a call was about, most telling first.
     * The first one a call carries as a non-empty string is the main argument.
     */
    private const MAIN_ARGUMENTS = ['file_path', 'path', 'command', 'pattern', 'url', 'query', 'description', 'prompt', 'name'];

    /** The main argument is cut to this many characters. */
    public const MAX_ARGUMENT_CHARS = 120;

    /**
     * @param array<array-key, mixed> $arguments
     * @param ?int    $ref    the ledger ref ({@see RefTag}) the result is shown under; when
     *                        given, the placeholder names the Recall tool with it
     * @param ?string $output the pruned output, hashed into the placeholder so a later
     *                        recall can be told apart from the output the model saw
     */
    public static function for(string $tool, array $arguments = [], ?int $ref = null, ?string $output = null): string
    {
        $tool = trim($tool) === '' ? 'tool' : self::oneLine($tool);
        $argument = self::mainArgument($arguments);

        return '[' . $tool . ($argument === null ? '' : ' ' . $argument)
            . ' — output pruned to save context; '
            . self::recovery($ref, $output, 're-run the tool if you need it') . ']';
    }

    /**
     * What a distilled output reads as (roadmap 3.B-3, DCP §13.2 C step 3):
     * a one-line header naming the tool and its main argument, then the text
     * the model wrote in the output's place —
     *
     *     [Read src/Tools/Bash.php — distilled by the model; re-run the tool for the full output]
     *     <distillation>
     *
     * Pure, like {@see for()}.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function distilled(
        string $tool,
        array $arguments,
        string $distillation,
        ?int $ref = null,
        ?string $output = null,
    ): string {
        $tool = trim($tool) === '' ? 'tool' : self::oneLine($tool);
        $argument = self::mainArgument($arguments);

        return '[' . $tool . ($argument === null ? '' : ' ' . $argument)
            . ' — distilled by the model; '
            . self::recovery($ref, $output, 're-run the tool for the full output') . "]\n" . $distillation;
    }

    /**
     * The clause that says how to get the full output back. With a ref it names
     * the `Recall` tool and the ref it takes, plus a short hash of the output
     * that was pruned, so the model can tell a re-run that returns different
     * bytes from the output it saw. Without one the placeholder keeps the
     * generic re-run wording, because there is nothing for Recall to take.
     */
    private static function recovery(?int $ref, ?string $output, string $fallback): string
    {
        if ($ref === null) {
            return $fallback;
        }

        $hash = $output === null ? '' : ' (content hash ' . substr(hash('sha256', $output), 0, 8) . ')';

        return 'recall it with Recall ref=' . RefTag::label($ref) . $hash;
    }

    /**
     * The call's main argument on one line and bounded, or null when it
     * carries no string argument at all.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function mainArgument(array $arguments): ?string
    {
        foreach (self::MAIN_ARGUMENTS as $key) {
            $value = $arguments[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return self::bounded($value);
            }
        }
        foreach ($arguments as $value) {
            if (is_string($value) && trim($value) !== '') {
                return self::bounded($value);
            }
        }

        return null;
    }

    private static function bounded(string $value): string
    {
        $value = self::oneLine($value);

        return mb_strlen($value) > self::MAX_ARGUMENT_CHARS
            ? mb_substr($value, 0, self::MAX_ARGUMENT_CHARS - 1) . '…'
            : $value;
    }

    private static function oneLine(string $value): string
    {
        // Without /u when the bytes are not UTF-8: a model-supplied argument
        // must not turn the placeholder into an empty string.
        $collapsed = preg_replace('/\s+/u', ' ', $value) ?? preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($collapsed);
    }
}
