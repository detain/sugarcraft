<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

/**
 * `/handoff --remote <url>` teleport client (roadmap C-SES-7.g).
 *
 * The remote arm of the handoff command pushes the current transcript to
 * another candy-code serve through the same websocket and token-resolution
 * discipline {@see \SugarCraft\Code\Cli\Attach} uses for `attach`: the token
 * is READ from the environment or the server state directory and never
 * minted here, and the dial is refused before any socket opens unless the
 * target is loopback `ws://` or TLS `wss://` (plain `ws://` off-loopback is
 * not permitted until C-WEB-5 ships serve TLS). The transcript rides the
 * wire as the JSON body `session.import` already accepts, so the server
 * side stays the C-SES-7.e machinery untouched.
 */
final class RemoteSessionPush
{
}
