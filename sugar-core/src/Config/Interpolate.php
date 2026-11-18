<?php

declare(strict_types=1);

namespace SugarCraft\Core\Config;

/**
 * Expands `${NAME}` and `${NAME:-default}` references inside a config value.
 *
 * A reference is replaced wherever it appears in the value, not only when it
 * spans the whole string, so `https://${HOST}/mcp` resolves its host. A value
 * with no reference passes through byte-for-byte, including the prose a
 * config often carries (`$5`, `100${}`, a lone `$`).
 *
 * The rules that decide what counts as "unset":
 *
 *   - A variable that the lookup returns false, null or the empty string for
 *     takes its default, or the empty string when there is no default.
 *   - `$zeroIsUnset` extends that to the string `0`. Only the MCP config
 *     reader wants this, because PHP's `?:` treats `0` as false. The provider
 *     reader keeps `0` as a real value.
 *   - `$upperCaseNamesOnly` restricts names to `[A-Z_][A-Z0-9_]*`. Only the
 *     provider reader wants this, so a lowercase `${foo}` in its config stays
 *     literal. The default accepts the shell alphabet `[A-Za-z_][A-Za-z0-9_]*`,
 *     and a name outside the alphabet (`${BAD NAME}`) is never a variable.
 *
 * If the regular expression fails to run on a pathological value, the value
 * comes back unchanged. That is the honest answer when nothing can be checked,
 * and it never invents a substitution.
 *
 * Replaces the two copies in candy-code: `ProviderFactory::resolveEnv()`
 * (uppercase names, `0` kept) and `McpClient::resolveEnv()` (shell names,
 * `0` unset). The provider reader's `${keychain:...}` pass is not env
 * expansion and stays with its caller.
 */
final class Interpolate
{
    private const SHELL_NAME = '[A-Za-z_][A-Za-z0-9_]*';

    private const UPPER_NAME = '[A-Z_][A-Z0-9_]*';

    private function __construct()
    {
    }

    /**
     * Expand every reference in $value.
     *
     * @param (callable(string): (string|false|null))|null $lookup  Resolves a
     *        variable name to its value. Defaults to the process environment,
     *        read with getenv(). Pass an explicit lookup to make a test
     *        hermetic, or to resolve names from a source other than the
     *        environment.
     */
    public static function expand(
        string $value,
        ?callable $lookup = null,
        bool $upperCaseNamesOnly = false,
        bool $zeroIsUnset = false,
    ): string {
        $lookup ??= static fn (string $name): string|false => getenv($name);
        $name = $upperCaseNamesOnly ? self::UPPER_NAME : self::SHELL_NAME;

        $expanded = preg_replace_callback(
            '/\$\{(' . $name . ')(?::-([^}]*))?\}/',
            static function (array $match) use ($lookup, $zeroIsUnset): string {
                $found = $lookup($match[1]);
                $unset = $found === false
                    || $found === null
                    || $found === ''
                    || ($zeroIsUnset && $found === '0');

                return $unset ? ($match[2] ?? '') : (string) $found;
            },
            $value,
        );

        return $expanded ?? $value;
    }
}
