<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

/**
 * Opens or validates the stderr-style report stream shared by middleware.
 *
 * Middleware constructors accept `resource|null $stderr`: an injected
 * resource is validated and used as-is (ownership stays with the caller),
 * while null means "open php://stderr for me". Centralised here so every
 * middleware rejects non-resources and reports open failures identically.
 */
final class StreamHelper
{
    /**
     * @param resource|null $stream Injected stream, or null to open $target
     * @param string        $target Stream URI opened when $stream is null
     * @param string        $mode   fopen mode for $target ('w' outputs,
     *                              'r' for a read stream such as php://stdin)
     * @return resource Native signatures cannot say it: `resource` is
     *                  not a PHP type (declaring `: resource` compiles
     *                  the name as a CLASS and fatals on the real
     *                  handle), so the native form is `mixed` and this
     *                  docblock carries the shape.
     * @throws \RuntimeException When $target cannot be opened
     * @throws \InvalidArgumentException When $stream is neither null nor a resource
     */
    public static function openOrValidate(mixed $stream, string $target = 'php://stderr', string $mode = 'w'): mixed
    {
        if ($stream === null) {
            $opened = fopen($target, $mode);
            if ($opened === false) {
                throw new \RuntimeException(Lang::t('middleware.cannot_open_stream', ['target' => $target]));
            }
            return $opened;
        }

        if (!is_resource($stream)) {
            throw new \InvalidArgumentException(Lang::t('middleware.stream_not_resource', ['target' => $target]));
        }

        return $stream;
    }
}
