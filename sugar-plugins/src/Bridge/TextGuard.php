<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

/**
 * Text hygiene at the router's two edges: inbound text is fenced before it
 * becomes a prompt, and outbound text has operator secrets scrubbed before a
 * platform sees it.
 */
interface TextGuard
{
    /** Wrap untrusted inbound text so the model reads it as data, not instructions. */
    public function fence(string $untrusted): string;

    /** Scrub outbound text of anything the operator named as a secret. */
    public function outbound(string $text): string;
}
