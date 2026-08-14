<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

/**
 * The launch's gating policy could not be determined, so the launch stops.
 *
 * Thrown only by {@see Bootstrap::permissionGate()} and {@see Bootstrap::hooks()},
 * and only for inputs that are PRESENT and UNUSABLE — an unreadable or
 * unparseable `~/.sugar-crush/config.json`, a `permissionMode` (in the config
 * or in `$SUGARCRUSH_PERMISSION_MODE`) that names no real
 * {@see \SugarCraft\Crush\Permissions\PermissionMode}, or a
 * `.sugar-crush/hooks.yaml` that exists and cannot be turned into the hook
 * chain it describes. Absence is not an error: a fresh install with no config
 * and no hook file at all gets the documented default.
 *
 * A dedicated type rather than a bare `\RuntimeException` so `bin/sugarcrush`
 * can turn it into the same clean exit-2 usage report every other
 * "your invocation is malformed, nothing ran" condition already produces,
 * instead of a PHP fatal with a stack trace over the user's terminal.
 */
final class PermissionConfigException extends \RuntimeException {}
