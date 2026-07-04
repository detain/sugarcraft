<?php

declare(strict_types=1);

namespace SugarCraft\Wish;

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
     * @return resource
     * @throws \RuntimeException When $target cannot be opened
     * @throws \InvalidArgumentException When $stream is neither null nor a resource
     */
    public static function openOrValidate($stream, string $target = 'php://stderr')
    {
        if ($stream === null) {
            $opened = fopen($target, 'w');
            if ($opened === false) {
                throw new \RuntimeException(Lang::t('middleware.cannot_open_stderr'));
            }
            return $opened;
        }

        if (!is_resource($stream)) {
            throw new \InvalidArgumentException(Lang::t('middleware.stderr_not_resource'));
        }

        return $stream;
    }
}
