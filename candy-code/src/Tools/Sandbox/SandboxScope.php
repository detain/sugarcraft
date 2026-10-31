<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

/**
 * The scopes the `sandboxScope` setting can ask the jail for (C-TOOL-4.d).
 *
 * `bashSandbox` decides WHETHER commands are jailed; this list decides WHICH
 * of the tool-less command runners join them — lifecycle hooks, the post-edit
 * lint, the post-edit format, the `autoTest` reflection run and the long-lived
 * servers (LSP language servers, MCP stdio servers), all of which shell out
 * with no tool call in the path and so never pass through `Bash`'s own jail.
 * The names are the settings vocabulary; {@see SCOPE_HOOKS}, {@see SCOPE_LINT},
 * {@see SCOPE_FORMAT}, {@see SCOPE_TEST}, {@see SCOPE_LSP} and {@see SCOPE_MCP}
 * are what the wiring reads.
 *
 * USER TIER ONLY, for the same reason `bashSandbox` is: {@see
 * \SugarCraft\Code\Config\LayeredSettings::PROJECT_TIER_KEYS} keeps it out of
 * what a checkout may set — a repository must not be able to move its own
 * hooks outside the jail its operator asked for, nor (reading the other way)
 * reach a scope list that changes what the launch confines.
 *
 * STRICTEST IN THE READ DIRECTION, mirroring {@see Bubblewrap::fromSetting()}'
 * "an unrecognised value means the tightest jail, never no jail": an entry
 * that is not one of the six names reads as EVERY scope. A typo cannot
 * silently drop a scope the operator wanted jailed — it jails everything
 * instead, and the doctor's settings-key audit says the list carries a value
 * it does not recognise while the run keeps the safer reading.
 */
final class SandboxScope
{
    /** The settings key holding the scope list. */
    public const SETTINGS_KEY = 'sandboxScope';

    /** Lifecycle hooks ({@see \SugarCraft\Code\Hooks\ScriptHook}). */
    public const SCOPE_HOOKS = 'hooks';

    /** The post-edit lint ({@see \SugarCraft\Code\Lint\LintRunner}). */
    public const SCOPE_LINT = 'lint';

    /** The post-edit formatter ({@see \SugarCraft\Code\Format\FormatRunner}). */
    public const SCOPE_FORMAT = 'format';

    /** The `autoTest` reflection run ({@see \SugarCraft\Code\Lint\TestRunner}). */
    public const SCOPE_TEST = 'test';

    /** The LSP language servers ({@see \SugarCraft\Code\LSP\LspLauncher}). */
    public const SCOPE_LSP = 'lsp';

    /** The MCP stdio servers ({@see \SugarCraft\Code\MCP\StdioMcpServer}). */
    public const SCOPE_MCP = 'mcp';

    /**
     * The recognised vocabulary, in the order the help text lists it.
     *
     * @var list<string>
     */
    public const SCOPES = [self::SCOPE_HOOKS, self::SCOPE_LINT, self::SCOPE_FORMAT, self::SCOPE_TEST, self::SCOPE_LSP, self::SCOPE_MCP];

    private function __construct() {}

    /**
     * Whether `$setting` (the raw `sandboxScope` value) asks for `$scope`.
     *
     * The single vocabulary read every scope decision funnels through — the
     * same rule {@see SandboxFactory::forScope()} applies, so no consumer
     * re-implements the unknown-entry reading. A missing key, an unusable
     * shape or an empty list asks for nothing; any entry outside
     * {@see SCOPES} asks for everything.
     */
    public static function requests(mixed $setting, string $scope): bool
    {
        if (!\is_array($setting) || $setting === []) {
            return false;
        }

        $names = [];
        foreach ($setting as $entry) {
            if (!\is_string($entry) || trim($entry) === '') {
                continue;
            }

            $names[] = strtolower(trim($entry));
        }

        if ($names === []) {
            return false;
        }

        foreach ($names as $name) {
            if (!\in_array($name, self::SCOPES, true)) {
                // Strictest reading: an entry outside the vocabulary jails
                // everything, exactly as a bad `bashSandbox` value means
                // `no-network` rather than `off`.
                return true;
            }
        }

        return \in_array($scope, $names, true);
    }
}
