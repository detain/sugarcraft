<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Crush\Hooks\BoundedHookInterface;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\Support\HookContextFiles;
use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\PathJail;

/**
 * After a `Write` or `Edit` (each file of an `ApplyPatch`), asks the file's language server to re-check it
 * and hands the model the ERRORS it reports — opencode's and Claude Code's
 * post-edit diagnostics loop (step 3.F): at most {@see MAX_ERRORS_PER_FILE}
 * per file, waiting at most {@see DEFAULT_WAIT_SECONDS} for the verdict.
 *
 *     LSP errors detected in this file, please fix:
 *     <diagnostics file="/abs/path.php">
 *     ERROR [12:5] Undefined variable '$x'.
 *     … and 3 more
 *     </diagnostics>
 *
 * ERRORS ONLY (severity 1), as opencode reports them: warnings and hints on
 * every edit are noise the model then "fixes" at the cost of the change it was
 * making. Line and column are 1-based, the way the Read tool numbers lines.
 *
 * IT NEVER REFUSES, for the reason {@see PostEditLintHook} gives: the edit has
 * already happened, and the report is advice riding on its result. A file no
 * server owns, a server that is down, and a server that says nothing in time
 * are all a bare ALLOW — silence is never dressed up as "no errors".
 *
 * BOUNDED: the wait is this hook's {@see timeoutSeconds()}, and a chain with
 * less time left hands it a shorter one.
 *
 * Registered by {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} only when the
 * user configured a server under `lsp` and at least one started.
 */
final readonly class PostEditDiagnosticsHook implements BoundedHookInterface
{
    /** Stable, so a hook file cannot register over it. */
    public const NAME = 'post-edit-diagnostics';

    /** opencode's per-file cap. */
    public const MAX_ERRORS_PER_FILE = 20;

    /** opencode's `DIAGNOSTICS_DOCUMENT_WAIT_TIMEOUT_MS`. */
    public const DEFAULT_WAIT_SECONDS = 5.0;

    /** LSP `DiagnosticSeverity.Error`. */
    private const SEVERITY_ERROR = 1;

    /** One diagnostic message's ceiling on the line it is printed on. */
    private const MAX_MESSAGE_CHARS = 400;

    public function __construct(
        private LspClient $client,
        private float $waitSeconds = self::DEFAULT_WAIT_SECONDS,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PostToolUse;
    }

    /** The tools that change a file's contents ({@see EditedFiles}). */
    public function matcher(): string
    {
        return EditedFiles::MATCHER;
    }

    public function timeoutSeconds(): float
    {
        return $this->waitSeconds;
    }

    public function withTimeoutSeconds(float $seconds): self
    {
        return new self($this->client, max(0.0, min($this->waitSeconds, $seconds)));
    }

    public function execute(HookContext $context): HookResult
    {
        if ($context->projectRoot === '') {
            return HookResult::allow();
        }

        $reports = [];
        foreach (EditedFiles::of($context) as $path) {
            $report = $this->reportFor($context, $path);
            if ($report !== null) {
                $reports[] = $report;
            }
        }

        return $reports === []
            ? HookResult::allow()
            : HookResult::allow('', HookContextFiles::bound(implode("\n\n", $reports), HookResult::MAX_ADDITIONAL_CONTEXT_BYTES));
    }

    /** One changed file's diagnostics block, or null when it has none to report. */
    private function reportFor(HookContext $context, string $path): ?string
    {
        if (str_contains($path, "\0")) {
            return null;
        }

        // JAILED EXACTLY AS THE EDIT WAS, for {@see PostEditLintHook}'s
        // reason: a `PostToolUse` chain also runs after an edit the tool
        // REFUSED, and a diagnostic quotes the code it flags — so a file the
        // edit could not reach is not sent to a server either. PathJail's one
        // answer outside the root, a saved tool-output file, is no edit target.
        $path = PathJail::resolve($context->projectRoot, $path);
        if ($path === null || !is_file($path) || ToolOutputSpill::readablePath($path) !== null) {
            return null;
        }

        $language = $this->client->languageFor($path);
        if ($language === null) {
            return null;
        }

        try {
            $fresh = $this->client->freshDiagnostics($language, $path, $this->waitSeconds);
        } catch (\Throwable) {
            return null;
        }
        if ($fresh === null || !$fresh['delivered']) {
            return null;
        }

        return self::render($path, $fresh['diagnostics']);
    }

    /**
     * The model-facing block for $diagnostics' errors, or null when there are
     * none.
     *
     * @param list<array<string, mixed>> $diagnostics
     */
    public static function render(string $path, array $diagnostics): ?string
    {
        $errors = array_values(array_filter(
            $diagnostics,
            static fn (mixed $d): bool => \is_array($d) && ($d['severity'] ?? null) === self::SEVERITY_ERROR,
        ));
        if ($errors === []) {
            return null;
        }

        $lines = [];
        foreach (\array_slice($errors, 0, self::MAX_ERRORS_PER_FILE) as $error) {
            $start = \is_array($error['range']['start'] ?? null) ? $error['range']['start'] : [];
            $line = \is_int($start['line'] ?? null) ? $start['line'] + 1 : 1;
            $column = \is_int($start['character'] ?? null) ? $start['character'] + 1 : 1;
            $message = \is_string($error['message'] ?? null) ? $error['message'] : '';
            $message = trim((string) preg_replace('/\s+/', ' ', Sanitize::untrusted($message)));
            $lines[] = sprintf('ERROR [%d:%d] %s', $line, $column, mb_substr($message, 0, self::MAX_MESSAGE_CHARS));
        }
        $more = \count($errors) - self::MAX_ERRORS_PER_FILE;
        if ($more > 0) {
            $lines[] = "… and {$more} more";
        }

        return "LSP errors detected in this file, please fix:\n"
            . '<diagnostics file="' . htmlspecialchars($path, ENT_QUOTES) . "\">\n"
            . implode("\n", $lines)
            . "\n</diagnostics>";
    }
}
