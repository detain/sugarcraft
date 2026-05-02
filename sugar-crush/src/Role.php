<?php

declare(strict_types=1);

namespace CandyCore\Crush;

/**
 * Conversation role for a {@see Message}. Mirrors the Anthropic /
 * OpenAI / Ollama wire vocabulary so backend adapters can pass
 * the value through without translation.
 */
enum Role: string
{
    case System    = 'system';
    case User      = 'user';
    case Assistant = 'assistant';
}
