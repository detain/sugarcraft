<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workspace;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Crush\Message;

/**
 * The one-line Conventional-Commits subject an auto-commit carries (step
 * 3.G): Aider's commit prompt asked of the cheap tool-less title model, and
 * what to do when there is no model, or its answer is unusable.
 *
 * The prompt is Aider's (`aider/prompts.py`, `commit_system`), unchanged in
 * substance: a `<type>: <description>` line in the imperative mood, at most
 * {@see MAX_SUBJECT_CHARS} characters, one of {@see TYPES}.
 *
 * A model answer is untrusted text that becomes part of the user's history,
 * so {@see subjectFrom()} keeps only its first usable line, strips escapes
 * and quoting, and makes sure it starts with a type — a reply that cannot be
 * turned into one is null, and the caller uses {@see fallback()}.
 */
final class CommitMessageWriter
{
    /** The Conventional-Commits types Aider's prompt allows. */
    public const TYPES = ['fix', 'feat', 'build', 'chore', 'ci', 'docs', 'style', 'refactor', 'perf', 'test'];

    /** A subject's ceiling (Aider: "Does not exceed 72 characters"). */
    public const MAX_SUBJECT_CHARS = 72;

    /**
     * The diff the model is shown is cut here, so the request fits a small
     * model's window; a cut diff says it was cut.
     */
    public const MAX_DIFF_BYTES = 24 * 1024;

    public const PROMPT = <<<'PROMPT'
        You are an expert software engineer that generates concise, one-line Git commit messages based on the provided diffs.
        Review the provided context and diffs which are about to be committed to a git repo.
        Review the diffs carefully.
        Generate a one-line commit message for those changes.
        The commit message should be structured as follows: <type>: <description>
        Use these for <type>: fix, feat, build, chore, ci, docs, style, refactor, perf, test

        Ensure the commit message:
        - Starts with the appropriate prefix.
        - Is in the imperative mood (e.g., "add feature" not "added feature" or "adding feature").
        - Does not exceed 72 characters.

        Reply only with the one-line commit message, without any additional text, explanations, or line breaks.
        PROMPT;

    /**
     * The request for the title model: the prompt, then the diff and the
     * request that produced it.
     *
     * @return list<Message>
     */
    public static function request(string $diff, string $context = ''): array
    {
        if (\strlen($diff) > self::MAX_DIFF_BYTES) {
            $diff = mb_strcut($diff, 0, self::MAX_DIFF_BYTES, 'UTF-8') . "\n… (diff cut at " . number_format(self::MAX_DIFF_BYTES) . ' bytes)';
        }
        $context = trim($context);

        return [
            Message::system(self::PROMPT),
            Message::user(
                ($context === '' ? '' : "<context>\n" . mb_substr($context, 0, 2000) . "\n</context>\n\n")
                . "<diff>\n" . $diff . "\n</diff>",
            ),
        ];
    }

    /**
     * The model's reply as a commit subject, or null when no line of it is
     * usable. A reply without a type gets `chore: `.
     */
    public static function subjectFrom(string $reply): ?string
    {
        $text = preg_replace('#<think>.*?</think>#is', '', $reply) ?? $reply;
        $text = Sanitize::untrusted($text);

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line, " \t`\"'*");
            $line = (string) preg_replace('/^(?:commit message|message|subject)\s*:\s*/i', '', $line);
            $line = trim((string) preg_replace('/\s+/', ' ', $line), " \t`\"'*");
            if ($line === '') {
                continue;
            }

            return self::subject($line);
        }

        return null;
    }

    /**
     * A deterministic subject for $paths when there is no model to ask or its
     * answer was unusable.
     *
     * @param list<string> $paths
     */
    public static function fallback(array $paths, string $verb = 'update'): string
    {
        $what = \count($paths) === 1 ? $paths[0] : \count($paths) . ' files';

        return self::subject(self::typeFor($paths) . ": {$verb} {$what}");
    }

    /**
     * The `edit`-mode subject: the `description` the model already wrote for
     * the `Edit`/`Write` call ("Rename the legacy config helper"), typed by the
     * path it touched. `edit` mode asks no model per edit — that would be an
     * unbilled call inside the tool chain on every edit — so the call's own
     * description is the message.
     *
     * @param list<string> $paths
     */
    public static function fromDescription(string $description, array $paths): string
    {
        $description = trim((string) preg_replace('/\s+/', ' ', Sanitize::untrusted($description)), " \t.`\"'");
        if ($description === '') {
            return self::fallback($paths);
        }
        if (self::typed($description)) {
            return self::subject($description);
        }

        return self::subject(self::typeFor($paths) . ': ' . mb_strtolower(mb_substr($description, 0, 1)) . mb_substr($description, 1));
    }

    /** Whether $line already starts with a Conventional-Commits type. */
    private static function typed(string $line): bool
    {
        return preg_match('/^(?:' . implode('|', self::TYPES) . ')(?:\([^)]*\))?!?: \S/', $line) === 1;
    }

    /** $line typed and cut to {@see MAX_SUBJECT_CHARS}. */
    private static function subject(string $line): string
    {
        if (!self::typed($line)) {
            $line = 'chore: ' . $line;
        }
        if (mb_strlen($line) > self::MAX_SUBJECT_CHARS) {
            $line = rtrim(mb_substr($line, 0, self::MAX_SUBJECT_CHARS - 1)) . '…';
        }

        return $line;
    }

    /**
     * `test` when every path is a test, `docs` when every path is
     * documentation, else `chore` — the one type a path alone cannot get wrong.
     *
     * @param list<string> $paths
     */
    private static function typeFor(array $paths): string
    {
        if ($paths === []) {
            return 'chore';
        }
        $all = static fn (callable $test): bool => array_filter($paths, static fn (string $p): bool => !$test($p)) === [];
        if ($all(static fn (string $p): bool => preg_match('#(^|/)(tests?|spec)/|Test\.[a-z]+$|_test\.[a-z]+$|\.test\.[a-z]+$#i', $p) === 1)) {
            return 'test';
        }
        if ($all(static fn (string $p): bool => preg_match('#(^|/)docs?/|\.(md|rst|adoc)$#i', $p) === 1)) {
            return 'docs';
        }

        return 'chore';
    }
}
