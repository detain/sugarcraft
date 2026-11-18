# sugar-serve-protocol

Wire contract for the `candy-code.v1` serve protocol: the WebSocket subprotocol names, the frame cap, the read-only halves of the server-state, discovery-record and token readers, and the address-to-WebSocket URL rule.

This is original work. The serve protocol is specific to SugarCraft's `candy-code` agent, so there is no upstream Charmbracelet port to cite.

## Install

```sh
composer require sugarcraft/sugar-serve-protocol
```

Requires PHP 8.3+. The package itself has no runtime dependency beyond PHP. Its test suite pins the `candy-code.v1` JSON-RPC 2.0 codec from `sugarcraft/sugar-mcp-client` (`SugarCraft\McpClient\Protocol\JsonRpc`), which is a dev-only requirement here.

## What it provides

- **`SugarCraft\ServeProtocol\Protocol`**: the wire constants. `MAJOR`, `SUBPROTOCOL` (`candy-code.v1`), `AUTH_SUBPROTOCOL_PREFIX` (`candy-code.auth.`), `MAX_SERVER_FRAME_BYTES` (4 MiB) and `ASK_EXPIRY_SECONDS` (1800).
- **`SugarCraft\ServeProtocol\Url\WebsocketUrl::from()`**: turns an `http(s)://` or `ws(s)://` address into the `ws://…/ws` or `wss://…/ws` URL a client dials. Returns `null` for any other scheme, and also for a malformed `http(s)://` or `ws(s)://` address the host currently turns into a URL: a bracketed host that is not an IPv6 literal (`http://[abc]:8080`), an unbracketed IPv6 host (`http://::1`, `http://2001:db8::1`), and a malformed or empty zone id (`http://[fe80::1%]:8080`). Those inputs are refused on purpose, so the candy-code host's `Attach::websocketUrl` output differs for them once it delegates here.
- **`SugarCraft\ServeProtocol\Discovery\StateDirReader::existing()`**: finds an existing serve state directory. A missing path returns `null`. A path that exists but is not a private directory (a symlink, a file, the wrong owner, or a mode other than `0700`) throws `RuntimeException`.
- **`SugarCraft\ServeProtocol\Discovery\DiscoveryRecordReader::read()`**: reads the `server.json` discovery record as a validated raw array. Returns `null` when the path is absent, a symbolic link, not a regular file, or not JSON within depth 8 that decodes to an array. Field-level checks stay with the host.
- **`SugarCraft\ServeProtocol\Auth\TokenFile::read()`**: reads the bearer token from a state directory. Returns the 64-hex token, or `null` when there is no usable token. Throws when the file exists but cannot be read, so an unreadable token is never replaced.

## Quickstart

```php
use SugarCraft\ServeProtocol\Auth\TokenFile;
use SugarCraft\ServeProtocol\Discovery\StateDirReader;
use SugarCraft\ServeProtocol\Url\WebsocketUrl;

$dir = StateDirReader::existing('/home/me/.local/state/candy-code');
$token = $dir === null ? null : TokenFile::read($dir);
$url = WebsocketUrl::from('http://127.0.0.1:7420'); // "ws://127.0.0.1:7420/ws"
```

## Scope

The library holds the read side and the constants only. Writing or rotating the token, creating the state directory, and running the server stay with the host application. The host keeps its own constants, with the same values, until it delegates to this package.

## Development

```sh
cd sugar-serve-protocol
composer install
vendor/bin/phpunit
```

## License

MIT
