<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support\Directories;

/**
 * Why a {@see DirectoryBrowser} would not resolve a path: {@see $reason} is
 * one of the REASON_* kinds, the message a sentence for a person.
 */
final class DirectoryBrowserException extends \RuntimeException
{
    public const REASON_NOT_FOUND = 'dir_not_found';

    public const REASON_NOT_DIRECTORY = 'not_a_directory';

    public const REASON_OUTSIDE_ROOT = 'outside_browse_root';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
