<?php

declare(strict_types=1);

namespace SugarCraft\Wish;

final class DeadlineExceededException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('context deadline exceeded');
    }
}
