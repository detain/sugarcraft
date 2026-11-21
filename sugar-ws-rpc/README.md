# SugarWsRpc

WebSocket RPC transport for SugarCraft. This package is a skeleton: its namespace and wiring exist, and the transport classes arrive in later changes.

> Original work. There is no upstream port, so no `Mirrors charmbracelet/...` citation applies.

```sh
composer require sugarcraft/sugar-ws-rpc
```

Requires PHP ≥ 8.3. The package has no runtime dependencies beyond PHP, and it does not depend on any other SugarCraft package.

The role of this library is the WebSocket transport that a SugarCraft host uses to exchange request and response messages with a remote peer, and to receive server-pushed frames on the same connection. The serve bridges of the channel bundles (`candy-discord`, `candy-msteams`) carry this transport today in their own copies. Those copies move here in a later step, so the bundles can share one tested client without each keeping a private socket layer.

Namespace: `SugarCraft\WsRpc\`. Source lives in `src/`, and tests in `tests/`.
