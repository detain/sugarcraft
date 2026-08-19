<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

/**
 * A launch input is PRESENT and UNUSABLE, so the launch stops.
 *
 * The name is narrower than the role has become, and the role is the accurate
 * description: thrown for any input whose intent cannot be recovered and whose
 * fallback would be more permissive or more surprising than refusing to start.
 * Measured over `Bootstrap`, that is an unreadable or unparseable
 * `~/.sugar-crush/config.json` ({@see Bootstrap::permissionConfig()}), a
 * `permissionMode` — from the config or from `$SUGARCRUSH_PERMISSION_MODE` —
 * naming no real {@see \SugarCraft\Crush\Permissions\PermissionMode}
 * ({@see Bootstrap::permissionGate()}), a `.sugar-crush/hooks.yaml` that exists
 * and cannot be turned into the hook chain it describes
 * ({@see Bootstrap::hooks()}), a home directory that cannot be determined or is
 * not this user's ({@see Bootstrap::homeDir()}), a `--root`/trusted path that
 * cannot be reached, and a `$SUGARCRUSH_MAX_COST` that is not a spend ceiling
 * ({@see Bootstrap::maxCostUsd()}).
 *
 * ABSENCE IS NOT AN ERROR, and that line is the whole discipline: a fresh
 * install with no config, no hook file and no environment overrides gets the
 * documented defaults.
 *
 * A dedicated type rather than a bare `\RuntimeException` so `bin/sugarcrush`
 * can turn it into the same clean exit-2 usage report every other
 * "your invocation is malformed, nothing ran" condition already produces,
 * instead of a PHP fatal with a stack trace over the user's terminal.
 */
final class PermissionConfigException extends \RuntimeException {}
