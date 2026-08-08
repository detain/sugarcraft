<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * Thrown when the LSP server sends a malformed or protocol-violating message.
 */
final class LspProtocolException extends \RuntimeException
{
}
