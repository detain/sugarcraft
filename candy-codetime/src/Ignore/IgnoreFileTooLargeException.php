<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Ignore;

/**
 * A rule file (.sugartrackignore / .sugartrackallow) larger than the
 * declared byte ceiling. A dedicated type (CT-S39, finding 059) so the CLI
 * reports it as the opt-out misconfiguration it is — never wrapped in the
 * storage-error envelope, which would tell the user their disk failed when
 * their config file was refused on size.
 */
final class IgnoreFileTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly string $rulePath,
        public readonly int $size,
        public readonly int $limit,
        string $label = 'ignore file',
    ) {
        parent::__construct(
            $label . ' ' . $rulePath . ' is ' . $size
            . ' bytes, over the ' . $limit . ' byte limit — refusing to read it',
        );
    }
}
