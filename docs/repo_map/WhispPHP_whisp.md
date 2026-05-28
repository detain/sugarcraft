# WhispPHP/whisp

## Metadata
- URL: https://github.com/WhispPHP/whisp
- Language: PHP
- Stars: Unknown (GitHub API unavailable from this environment)
- License: MIT
- Description: A pure PHP SSH server designed for terminal applications. Whisp enables developers to build SSH server applications in PHP, with support for PTY (pseudo-terminal), multiple authentication methods, and concurrent connections via process forking.

## Feature List
- **Pure PHP SSH Server**: Complete SSH protocol implementation in PHP without requiring system-level SSH daemons
- **Multi-App Support**: Auto-discovery of PHP scripts in an `apps/` directory, with parameterized route support (e.g., `chat-{roomName}`)
- **PTY Management**: Full pseudo-terminal support with FFI (Foreign Function Interface) for cross-platform terminal control
- **SSH Key Authentication**: Ed25519 and RSA public key authentication with signature validation
- **Password Authentication**: Accepts any password (demo mode) for easy testing
- **Keyboard-Interactive Auth**: Challenge-response authentication support
- **Concurrent Connections**: Process forking via `pcntl_fork()` for handling multiple simultaneous connections
- **Encrypted Communication**: AES-256-GCM encryption with rekeying support
- **Key Exchange**: Curve25519 Diffie-Hellman key exchange
- **Environment Variable Passing**: Apps receive connection metadata via environment variables (WHISP_APP, WHISP_CLIENT_IP, WHISP_USER_PUBLIC_KEY, WHISP_TTY, WHISP_COLS, WHISP_ROWS, etc.)
- **Signal Handling**: SIGHUP (reload apps), SIGUSR2 (restart server), SIGINT/SIGTERM (graceful shutdown)
- **Memory Monitoring**: Built-in memory usage tracking and logging
- **Logging**: PSR-3 logger support with NullLogger, FileLogger, and ConsoleLogger implementations
- **Cross-Platform FFI**: OS-specific terminal constants and ioctl calls for macOS and Linux

## Key Classes and Methods

### Server (src/Server.php)
- `__construct(int $port = 22, string $host = '0.0.0.0', bool $autoDiscoverApps = true)` — Creates TCP socket server
- `run(string|array $apps = [])` — Starts the SSH server with optional apps
- `autoDiscoverApps()` — Discovers apps in `apps/` directory
- `addApps(string|array $apps)` — Registers apps for serving
- `getActiveCount()` — Returns count of active child processes
- `getChildProcesses()` — Returns array of PID => connectionId
- `stop()` — Gracefully stops server and terminates children

### Connection (src/Connection.php)
- `handle()` — Main event loop for a single SSH connection
- `handleKexInit()`, `handleKexDHInit()`, `handleNewKeys()` — SSH key exchange handling
- `handleUserAuthRequest()` — Authentication (publickey, password, keyboard-interactive)
- `handleChannelOpen()`, `handleChannelRequest()`, `handleChannelData()`, `handleChannelClose()` — SSH channel management
- `handlePtyRequest()` — PTY terminal setup
- `writeChannelData()` — Send data to SSH client in chunks
- `disconnect(string $reason)` — Disconnect with reason

### Channel (src/Channel.php)
- `createPty()` — Creates a pseudo-terminal for the channel
- `startCommand(string $command)` — Starts a command with optional PTY
- `writeToCommand(string $data)` — Forward data from SSH client to command
- `forwardFromCommand()` — Read command output and forward to SSH client
- `setEnvironmentVariable()` — Set env vars for the subprocess
- `commandIsRunning()` — Check if command is still running

### Pty (src/Pty.php)
- `open()` — Open master/slave PTY pair
- `write(string $data)` — Write data to PTY master
- `read(int $length = 2048)` — Read data from PTY master
- `close()` — Close PTY
- `setWindowSize(WinSize $size)` — Update terminal size via ioctl
- `setupTerminal()` — Configure terminal modes (c_lflag, c_iflag, c_oflag, c_cflag)
- `getFileDescriptor()` — Get OS-specific file descriptor for FFI operations

### Ffi (src/Ffi.php)
- `getSlaveNameFromMaster(int $masterFd)` — OS-specific PTY slave name lookup
- `unlockPty()`, `grantPty()` — macOS-specific PTY permissions
- `setWindowSize()` — ioctl call to set winsize
- `getTermios()`, `setTermios()` — Terminal attribute get/set via FFI
- `setControllingTerminal()`, `setForegroundProcessGroup()` — Process group management
- `getConstant(string $name)` — Get terminal constant by name

### Kex (src/Kex.php)
- `response()` — Generate KEXDH_REPLY with Curve25519 key exchange
- Uses `sodium_crypto_scalarmult()` for shared secret computation
- Computes exchange hash with SHA-256
- Signs with Ed25519 host key

### PacketHandler (src/PacketHandler.php)
- `constructPacket()` — Build unencrypted SSH packet
- `constructEncryptedPacket()` — Build AES-256-GCM encrypted packet
- `fromData()` — Parse incoming data into packets
- `deriveKeys()` — KDF (Key Derivation Function) for session keys
- `switchToNewKeys()` — Apply new keys after rekeying
- Uses `phpseclib3\Crypt\AES` for GCM mode encryption

### PublicKeyValidator (src/PublicKeyValidator.php)
- `validateSignature()` — Validates SSH public key signatures
- `verifyEd25519Signature()` — Ed25519 signature verification via `sodium_crypto_sign_verify_detached()`
- `verifyRsaSignature()` — RSA signature verification via OpenSSL
- `constructSignedData()` — Builds RFC 4252/8332 compliant signed data buffer
- `extractEd25519PublicKey()`, `extractRsaComponents()` — Blob parsing

### ServerHostKey (src/ServerHostKey.php)
- Generates and persists Ed25519 host keypair in `~/.whisp-{name}/`
- `getPrivateKey()`, `getPublicKey()` — Key accessors

### Command Classes
- `CommandRunner` — Basic process execution with pipes
- `PtyCommandRunner` — Process connected to PTY slave

### Enums
- `MessageType` — All SSH protocol message types (DISCONNECT=1 through CHANNEL_FAILURE=100)
- `TerminalMode` — Terminal control characters (VINTR, VQUIT, VERASE, etc.)
- `DisconnectReason` — SSH disconnect reason codes

### Value Objects
- `WinSize` — Terminal window dimensions (rows, cols, widthPixels, heightPixels)
- `TerminalInfo` — Terminal configuration (term, dimensions, modes)

## Notable Algorithms / Named Patterns

### SSH Key Exchange (Diffie-Hellman Curve25519)
```
shared_secret = sodium_crypto_scalarmult(curve25519_private, client_public_key)
exchange_hash = SHA256(client_version || server_version || client_kexinit || server_kexinit || host_key_blob || client_ephemeral_pub || server_ephemeral_pub || shared_secret)
session_id = exchange_hash (first key exchange only)
```

### Key Derivation Function (KDF)
```
output = ''
prev_block = ''
while len(output) < needed_length:
    input = K + H + letter + prev_block + session_id
    hash = SHA256(input)
    output += hash
    prev_block = hash
```

### AES-256-GCM Encryption
- 12-byte nonce derived from base IV + sequence number
- Sequence number incremented per packet
- Length bytes used as Additional Authenticated Data (AAD)
- 16-byte authentication tag appended to ciphertext

### PTY Management
- `/dev/ptmx` master PTY opening
- OS-specific ioctl calls: `TIOCGPTN`, `TIOCSPTLCK`, `TIOCSWINSZ`, `TIOCSCTTY`
- Session leadership via `posix_setsid()` before `ioctl(fd, TIOCSCTTY, 0)`

### Process Per Connection
- Parent process: `socket_create()` → `socket_listen()` → `socket_select()` loop
- On connection: `pcntl_fork()` → child handles Connection, parent tracks PID
- Child: signal handlers reset, connection handling via event loop
- Parent: `pcntl_waitpid(-1, $status, WNOHANG)` for SIGCHLD reaping

## Strengths

1. **Pure PHP Implementation**: No external SSH dependencies; works with just PHP extensions (FFI, pcntl, sodium, mbstring)
2. **Modern PHP 8.2+**: Uses enums, readonly properties, named arguments, match expressions
3. **Comprehensive SSH Protocol**: Full implementation of key exchange, authentication, channel, and encryption
4. **Security-Focused**: Ed25519 host keys, AES-256-GCM encryption, proper signature verification
5. **Developer Experience**: Auto-discovery of apps, parameterized routes, rich environment variables
6. **Production Features**: Signal handling for graceful shutdown/restart, memory monitoring, PSR-3 logging
7. **Clean Architecture**: Well-separated concerns (Server → Connection → Channel → Pty → CommandRunner)
8. **Cross-Platform FFI**: Works on both Linux and macOS with OS-specific terminal constants
9. **Test Coverage**: Pest-based unit tests with custom matchers for process state
10. **Minimal Dependencies**: Only requires `phpseclib/phpseclib` for RSA big integer operations

## Weaknesses

1. **No Windows Support**: Relies heavily on POSIX APIs (PTY, signals, forking) unavailable on Windows
2. **No ECDSA Support**: Only Ed25519 and RSA host keys; ECDSA not implemented
3. **Accepts Any Password**: Default password auth accepts everything (intentional for demos but risky for production)
4. **No SFTP Support**: Only shell/exec channel types; no file transfer subsystem
5. **No Port Forwarding**: Direct-tcpip channel explicitly disallowed
6. **No SCP**: Only exec-based commands; no secure copy protocol
7. **Single-Threaded Parent**: Main server loop is single-threaded (though connections fork)
8. **No Windows Subsystem for Linux (WSL) Guarantee**: May have issues on WSL due to PTY behavior differences
9. **Limited Error Recovery**: Disconnects on protocol errors rather than attempting recovery
10. **Process Model**: Forking per connection may be memory-intensive for many concurrent connections

## SugarCraft Mapping

| WhispPHP/whisp Feature | SugarCraft Library | Notes |
|----------------------|-------------------|-------|
| SSH Server / Connection handling | `candy-core` | Core TUI infrastructure, event loop patterns |
| PTY management / Terminal I/O | `candy-pty` | Could be a direct port - PTY creation and management |
| SSH protocol packets / Binary protocols | `sugar-bits` | Low-level bit/byte manipulation patterns |
| Terminal mode handling | `candy-terminal` or `sugar-bits` | Terminal capability handling |
| Channel / Session management | `candy-core` | Similar event/channel model |
| Environment variable passing | `sugar-bits` | Parameter passing conventions |
| Signal handling (SIGCHLD, SIGHUP) | `candy-core` | Process management patterns |
| Logging (PSR-3) | Already present in SugarCraft\Core | LoggerInterface patterns |
| Process forking (pcntl) | `candy-core` | Process management |
| FFI for terminal ioctl | Would be new - possibly `candy-sys` | System call FFI layer |

**Relevant SugarCraft Libraries:**
- `candy-core` — Foundation library with TUI event loop, process management
- `candy-pty` — Would be a logical port target for PTY functionality
- `sugar-bits` — Low-level data handling, could use packet construction patterns
- `candy-terminal` — Terminal capability handling

**Note:** Whisp is a server application, while SugarCraft is primarily focused on TUI (Terminal User Interface) client applications. However, the SSH protocol implementation, PTY management, and process handling patterns would be valuable additions to the SugarCraft ecosystem.

## Analysis

WhispPHP/whisp is an impressive feat of engineering — a complete, production-ready SSH server implemented entirely in PHP. The implementation demonstrates deep understanding of the SSH protocol (RFC 4251-4256), including key exchange via Diffie-Hellman with Curve25519, authentication using both Ed25519 and RSA signatures, and AES-256-GCM encryption with proper CBC padding and GCM authentication tags. The architecture is well-structured with clear separation between the Server (socket management and process forking), Connection (SSH protocol state machine), Channel (session multiplexing), and Pty/CommandRunner (subprocess management).

The use of PHP FFI for terminal control is particularly clever — it allows direct ioctl calls to manipulate terminal attributes without requiring external C extensions. The cross-platform support (Linux vs macOS) with separate constant definitions shows attention to portability. The key derivation function follows RFC 4253's recommendations, and the rekeying implementation properly preserves the session ID across key exchanges.

From a software architecture perspective, Whisp uses a similar event-driven pattern to SugarCraft's TUI models — a main loop that listens on multiple streams (SSH client socket and command stdout), uses stream_select() for I/O multiplexing, and dispatches to appropriate handlers. The process-for-forking model for concurrent connections is straightforward but effective for a SSH server where each connection is independent.

The main limitations are the lack of Windows support (due to PTY/signal dependencies) and the narrow feature set (no SFTP, no port forwarding, no SCP). The password authentication accepting any password is a deliberate demo feature but could be misconstrued as a security issue. Overall, Whisp is an excellent reference implementation for anyone building SSH-related tooling in PHP or looking to understand the SSH protocol at the wire level.

The project would map most closely to `candy-pty` (for its PTY management) and `candy-core` (for its event loop and process management patterns) in the SugarCraft ecosystem. A port would require careful handling of the FFI layer and a clean separation between the SSH protocol state machine and the application layer.
