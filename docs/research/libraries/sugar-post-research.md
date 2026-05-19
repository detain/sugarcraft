# sugar-post Research: Terminal Social Media TUI Patterns

**Date:** 2026-05-13
**Library:** sugar-post (PHP port of charmbracelet/pop)
**Researcher:** Research Agent

---

> ## ⚠️ Decision update — 2026-05-19
>
> **sugar-post is staying an email tool.** A social-media TUI pivot
> was considered and rejected. See
> [`plans/sugar-post-identity.md`](../../../plans/sugar-post-identity.md)
> for the full memo and rationale.
>
> Treat the social-media sections of this doc (§2.2 onward, including
> Mastodon / Bluesky / X client benchmarks like Perch, Mastui, Toot,
> Unrager, Tootles, TerminalRant) as **archival reference for a
> hypothetical future `sugar-toot` / `sugar-feed` library** — not as
> the sugar-post roadmap. The shipped sugar-post correctly tracks
> [`charmbracelet/pop`](https://github.com/charmbracelet/pop) (email
> TUI); the next sugar-post work is the upstream Pop TUI surface
> (Bubble Tea Model + filepicker + spinner + markdown preview), not
> microblog features.

---

## Executive Summary

**Critical Finding:** sugar-post is currently an **email client** (sending via Resend API or SMTP), not a social media TUI. The README and source confirm it's a direct port of `charmbracelet/pop` for sending emails. This research examines the current architecture and provides recommendations for either enhancing it as an email TUI or pivoting toward a social media TUI implementation.

**Source:** `/home/sites/sugarcraft/sugar-post/README.md:L13`
> "PHP port of [charmbracelet/pop](https://github.com/charmbracelet/pop) — send emails from PHP via Resend API or direct SMTP."

---

## Part 1: Current Architecture Analysis

### 1.1 Core Components

The current sugar-post follows a clean transport pattern:

| Component | File | Responsibility |
|-----------|------|----------------|
| `Email` | `src/Email.php` | Immutable value object for email messages |
| `Attachment` | `src/Attachment.php` | Immutable file attachment (path or inline content) |
| `Transport` | `src/Transport.php` | Interface for sending implementations |
| `ResendTransport` | `src/ResendTransport.php` | HTTP API transport via Resend |
| `SmtpTransport` | `src/SmtpTransport.php` | Direct SMTP with TLS support |
| `Mailer` | `src/Mailer.php` | High-level API wrapping a Transport |
| `Lang` | `src/Lang.php` | i18n facade wrapping `SugarCraft\Core\I18n\T` |

**Source:** `sugar-post/src/` (all files)

### 1.2 Current Strengths

1. **Immutable Value Objects** — `Email` and `Attachment` use readonly properties with `with*()` builders
2. **Clean Transport Interface** — Easy to add new transports (SendGrid, Mailgun, etc.)
3. **Full i18n Setup** — 17 locales already translated in `lang/` directory
4. **Dual Transport Support** — Resend API + SMTP with equal fidelity
5. **MIME Building** — Complete multipart MIME construction in SmtpTransport

**Source:** `src/Email.php:L17-L32` (immutable properties), `src/Transport.php:L10-L22` (interface)

### 1.3 Current Limitations

| Area | Issue | Severity |
|------|-------|----------|
| **TUI** | No actual TUI implementation — only CLI binary | Critical |
| **Account Management** | No OAuth/account storage | High |
| **Timeline** | N/A — email has no timeline | Critical |
| **Post Composition** | Basic textarea, no rich editor | Medium |
| **Media Handling** | File attachments only, no inline image rendering | Medium |
| **Real-time Updates** | None — email is not real-time | N/A |

**Source:** `bin/pop` (CLI only, no TUI component found)

---

## Part 2: Research on Upstream and Competing Tools

### 2.1 Upstream: charmbracelet/pop (Go)

**Repository:** https://github.com/charmbracelet/pop
**Stars:** 2,809 | **Language:** Go

Pop is the reference implementation. Key architectural decisions:

```go
// Source: charmbracelet/pop/model.go
type Model struct {
    state           State
    From            textinput.Model
    To              textinput.Model
    Subject         textinput.Model
    Body            textarea.Model
    Attachments     list.Model
    showCc          bool
    Cc              textinput.Model
    Bcc             textinput.Model
    filepicker      filepicker.Model
    Spinner         spinner.Model
    help            help.Model
    keymap          KeyMap
    // ...
}
```

**Key Patterns from Pop:**
- **TUI-first design** — Bubble Tea framework with clear Model/Update/View separation
- **State machine** — `State` enum for `idle`, `pickingFile`, `sendingEmail`, `hoveringSendButton`
- **Markdown body** — Body textarea converts markdown to HTML before sending
- **Filepicker integration** — Uses `charmbracelet/filepicker` for attachment selection
- **Markdown preview** — Can preview rendered email before sending

**Pop Key Bindings:**
| Key | Action |
|-----|--------|
| `tab` | Cycle focus |
| `ctrl+enter` | Send email |
| `esc` | Cancel |

**Source:** https://github.com/charmbracelet/pop (model.go, README.md)

### 2.2 Rust Social Media TUIs

#### 2.2.1 Perch (Mastodon + Bluesky)

**Repository:** https://github.com/ricardodantas/perch
**Stars:** 63 | **Language:** Rust (81.9%), Astro (18%)

**Architecture:**
```
perch/
├── app/           # TUI application (Bubble Tea or Ratatui)
├── cli/           # CLI commands ( clap)
├── api/           # Network layer
├── config/        # Config management
└── models/        # Domain models
```

**Key Features:**
- **Three-panel layout:** Accounts left, Timeline center, Post detail right
- **Cross-posting:** Single post to multiple networks simultaneously
- **Vim keybindings:** `j/k` navigation, `n` new post, `r` refresh
- **Scheduled posts:** Queue posts with `in 2h` or `YYYY-MM-DD HH:MM` syntax
- **Themes:** Dracula, Cyberpunk, TokyoNight, Catppuccin, Nord
- **Drafts:** Save drafts for later

**Perch CLI Patterns:**
```bash
perch post "Hello world!"                           # Post to all
perch post "Hello!" --to mastodon,bluesky          # Specific networks
perch post "Spoiler" --cw "Movie spoilers"         # Content warning
perch post "Check this!" --media ~/photo.jpg       # With media
perch timeline --limit 50                          # View timeline
perch schedule daemon --interval 30                # Run scheduler
```

**Source:** https://github.com/ricardodantas/perch (README.md, crates.io)

#### 2.2.2 Mastui (Python + Textual)

**Repository:** https://github.com/kimusan/mastui
**Stars:** N/A | **Language:** Python

**Key Features:**
- **Multi-column layout:** Home, Local, Federated, Mentions side-by-side
- **Multi-profile support:** Each account has isolated config, keymap, credentials
- **SQLite cache:** Persistent timeline cache for offline reading
- **Image rendering:** ANSI, Sixel, or TGP with auto-detection
- **Content warnings, polls, autocomplete** for @mentions and #hashtags
- **CSS theming:** Custom theme overrides per profile

**Technology Stack:**
- Textual framework
- Mastodon.py API client
- httpx for HTTP
- html2text for HTML→Markdown

**Source:** https://github.com/kimusan/mastui (README.md)

#### 2.2.3 Toot (Python + Urwid)

**Repository:** https://github.com/ihabunek/toot
**Stars:** 1,301 | **Language:** Python

**Key Features:**
- **Curses-based TUI** with `toot tui`
- **Image display:** Block graphic, Kitty, iTerm2 protocols
- **Account switching:** `toot activate` for multiple accounts
- **Full Mastodon API coverage:** Posting, replying, deleting, favoriting, reblogging
- **Search:** Account and hashtag search

**Source:** https://github.com/ihabunek/toot (README.md)

#### 2.2.4 Unrager (Rust - X/Twitter)

**Repository:** https://github.com/guitaripod/unrager
**Stars:** 3 | **Language:** Rust

**Key Features:**
- **LLM rage filter:** Local Ollama filters rage-bait before rendering
- **TUI + CLI dual mode:** `unrager` for TUI, `unrager home -n 20` for CLI
- **Session persistence:** Source, selection, split width survive restarts
- **OAuth 2.0 write path** for posting
- **Commands:** `unrager tweet "..."`, `unrager whoami`, `unrager doctor`

**Source:** https://github.com/guitaripod/unrager (README.md)

#### 2.2.5 Twit (Rust - X/Twitter)

**Repository:** https://github.com/blacktop/twit
**Stars:** 7 | **Language:** Rust

**Key Features:**
- **AI summarization** of links and tweets (`s` key)
- **TTS voice reading** of summaries (`v` key)
- **Multiple AI providers** support
- **Debug mode** for troubleshooting

**Source:** https://github.com/blacktop/twit (README.md)

#### 2.2.6 TerminalRant (Go - Mastodon)

**Repository:** https://github.com/CrestNiraj12/terminalrant
**Language:** Go

**Key Features:**
- **OAuth login** with browser-based auth
- **Home timeline** from followed users
- **Custom hashtag tab** for topic tracking
- **Vim keybindings:** `j/k` navigation, `p/P` new post, `c/C` reply
- **Configurable instance** via `TERMINALRANT_INSTANCE`

**Source:** https://github.com/CrestNiraj12/terminalrant (README.md)

### 2.3 Python Social Media TUIs

#### 2.3.1 Tootles (Python + Textual)

**Repository:** https://github.com/tootles-dev/tootles
**Language:** Python

**Key Features:**
- **Web UI parity:** Home, Notifications, Explore, Bookmarks, Favorites, Lists
- **CSS-based theming** with hot-reload
- **Fuzzy search** command palette
- **Real-time streaming** timeline updates
- **Textual-native** animations and interactions

**Source:** https://github.com/tootles-dev/tootles (README.md)

### 2.4 Instagram CLI

**Repository:** https://github.com/AllanLotta/instagram-cli (TypeScript) and https://github.com/hadvand/instagram-cli (Python)

**Key Features:**
- **Full keyboard navigation** with vim-style bindings
- **Image rendering protocols:** ASCII, halfBlock, Braille, Kitty, iTerm2, Sixel
- **Chat interface** with threads
- **Feed and stories** viewing
- **Config file:** `~/.instagram-cli/config.ts.yaml`

**Source:** https://github.com/AllanLotta/instagram-cli (README.md)

---

## Part 3: Pattern Analysis

### 3.1 Account Management Patterns

| Tool | Approach | Auth Method |
|------|----------|-------------|
| **Perch** | Multi-account with `perch accounts` | OAuth per network |
| **Mastui** | Sandboxed per-profile config directories | Mastodon access token |
| **Toot** | `toot activate` account switching | Mastodon OAuth |
| **Unrager** | Session-based cookie auth | Browser cookies + OAuth 2.0 PKCE |
| **birdy** | Token rotation across accounts | X auth tokens |

**Best Pattern for sugar-post:**
```
sugar-post account add <name> --network mastodon --token <token>
sugar-post account list
sugar-post account remove <name>
```

**Key Insight:** Account credentials should be stored in platform-specific secure storage (Keychain on macOS, Secret Service on Linux).

**Source:** Perch README, Mastui README, Unrager README

### 3.2 Timeline Rendering Patterns

| Tool | Approach | Framework |
|------|----------|-----------|
| **Perch** | Three-panel: accounts/filter + timeline + detail | Ratatui |
| **Mastui** | Multi-column side-by-side | Textual |
| **Toot** | Single-column list with scroll | Urwid |
| **Tootles** | Web UI parity with multiple tabs | Textual |

**Timeline Rendering Best Practices:**

1. **Virtual scrolling** — Only render visible items for performance
2. **Date grouping** — "Today", "Yesterday", "This Week" separators
3. **Relative timestamps** — "2h ago", "yesterday" with hover for absolute
4. **Unread indicators** — Bold or highlight new posts
5. **Multi-network merge** — Chronological interleaving with network badges

**Example Timeline Item Structure:**
```
┌─────────────────────────────────────────────────────────┐
│ @username · 2h ago                    [Mastodon] [★]    │
│ Preview of post content here truncated to two lines...  │
│ 🔁 12  ♥ 45  💬 8                                    │
└─────────────────────────────────────────────────────────┘
```

**Source:** Perch TUI layout, Mastui multi-column design

### 3.3 Post Composition Patterns

| Tool | Approach | Features |
|------|----------|----------|
| **Pop (upstream)** | Textarea with markdown | Preview, filepicker |
| **Perch** | Inline composer with `n` key | Network toggle, CW, media |
| **Mastui** | Full composer modal | Character counter, autocomplete, polls |
| **Unrager** | `$EDITOR` or inline | Draft support |

**Composition Component Structure:**
```php
// Recommended: Composer class
final class Composer {
    public readonly string $content;
    public readonly ?string $contentWarning;
    public readonly Visibility $visibility;  // public, unlisted, private, direct
    public readonly array $mediaAttachments;
    public readonly ?string $scheduledAt;
    public readonly array $targetNetworks;  // for cross-posting

    public function withContent(string $content): self;
    public function withContentWarning(string $cw): self;
    public function withMedia(string $path): self;
    public function withSchedule(DateTimeImmutable $when): self;
}
```

**Key Features to Implement:**
1. **Character counter** — With network-specific limits (500 for Mastodon, 280 for X)
2. **Content warning toggle** — Show/hide sensitive content
3. **Visibility selector** — Public, Unlisted, Private, Direct
4. **Media upload** — With preview and alt text
5. **@mention autocomplete** — From following list
6. **#hashtag autocomplete** — From trending/own use
7. **Draft save** — Persist to disk for later

**Source:** Mastui composer features, Perch compose dialog

### 3.4 Media Handling Patterns

| Tool | Protocol | Use Case |
|------|----------|----------|
| **Kitty** | Kitty graphics protocol | High-quality images |
| **iTerm2** | Inline images | macOS terminal |
| **Sixel** | DEC Sixel | Legacy terminals |
| **ANSI** | ANSI escape codes | Basic terminal |
| **Braille** | Unicode braille patterns | High density |

**Image Rendering in PHP:**
```php
// Using sugar-glow or img2txt library
final class MediaRenderer {
    public function renderImage(string $path, RenderProtocol $protocol): string {
        return match($protocol) {
            RenderProtocol::KITTY => $this->kittyRender($path),
            RenderProtocol::ITERM2 => $this->iterm2Render($path),
            RenderProtocol::SIXEL => $this->sixelRender($path),
            RenderProtocol::ANSI => $this->ansiBlockRender($path),
        };
    }
}
```

**Best Practice:** Auto-detect terminal capability and fall back gracefully.

**Source:** Instagram CLI image protocols, Mastui image rendering

### 3.5 Real-time Updates (Streaming) Patterns

| Tool | Approach | Technology |
|------|----------|------------|
| **Tootles** | Server-Sent Events (SSE) | `httpx` streaming |
| **Perch** | Polling with `daemon` command | Configurable interval |
| **Mastui** | Auto-refresh cadence | Per-timeline configurable |

**Streaming Implementation Pattern:**
```php
// Using ReactPHP for async streaming
final class TimelineStream {
    public function stream(Account $account): \Generator {
        $client = new \React\Http\Browser();
        $url = $account->streamingEndpoint();

        $response = yield $client->request($url, [
            'Authorization' => 'Bearer ' . $account->accessToken,
        ]);

        $body = $response->getBody();
        while (!$body->eof()) {
            $line = yield $body->read(8192);
            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
                yield new TimelineUpdate($data);
            }
        }
    }
}
```

**Key Insight:** Mastodon uses SSE (`/api/v1/streaming/public`), X uses WebSocket.

**Source:** Tootles streaming, Mastui auto-refresh

---

## Part 4: Recommendations for sugar-post

### 4.1 Option A: Enhance as Email TUI

If the goal is to complete the email TUI port from Pop:

| Priority | Improvement | Effort | Impact |
|----------|-------------|--------|--------|
| **P0** | Add TUI using PHP TUI framework (php-term/clipboard or custom) | High | High |
| **P1** | Implement Bubble Tea-style Model/Update/View pattern | Medium | High |
| **P1** | Add markdown body → HTML conversion | Medium | Medium |
| **P2** | Add filepicker integration for attachments | Medium | Medium |
| **P2** | Add email preview before sending | Low | Medium |
| **P3** | Add HTML email rendering for received emails | High | Low |

**Source:** charmbracelet/pop TUI implementation

### 4.2 Option B: Pivot to Social Media TUI

If the goal is to build a social media TUI:

| Priority | Improvement | Effort | Impact |
|----------|-------------|--------|--------|
| **P0** | Add Mastodon API client transport | Medium | High |
| **P0** | Add account management (OAuth + storage) | High | High |
| **P0** | Implement timeline renderer | High | High |
| **P1** | Add post composer with CW, visibility, media | Medium | High |
| **P1** | Add vim keybindings | Low | High |
| **P1** | Add multi-account support | Medium | Medium |
| **P2** | Add Bluesky transport | Medium | Medium |
| **P2** | Add scheduled posts | Medium | Medium |
| **P2** | Add real-time streaming | Medium | Medium |
| **P3** | Add image rendering (Kitty/iTerm2/Sixel) | High | Medium |

**Source:** Perch, Mastui, Tootles architecture

### 4.3 Recommended Architecture

For either option, adopt this layered architecture:

```
┌─────────────────────────────────────────────────────┐
│                    CLI Layer                        │
│  bin/sugar-post (commands: send, account, timeline) │
├─────────────────────────────────────────────────────┤
│                    TUI Layer                        │
│  Interactive terminal UI with views and keybindings │
├─────────────────────────────────────────────────────┤
│                  Service Layer                      │
│  Mailer / TimelineService / ComposerService         │
├─────────────────────────────────────────────────────┤
│                 Transport Layer                     │
│  ResendTransport / SmtpTransport / MastodonTransport│
├─────────────────────────────────────────────────────┤
│                  Network Layer                      │
│  HTTP Client / OAuth / Streaming                    │
└─────────────────────────────────────────────────────┘
```

**Key Architectural Principles:**
1. **Immutable value objects** — Already implemented for Email/Attachment
2. **Transport interface** — Already implemented, extend for social APIs
3. **Service layer** — Add for complex operations (timeline, composer)
4. **Async-first** — Use ReactPHP for streaming and concurrent requests

**Source:** Perch architecture, Mastui service layer

---

## Part 5: Specific Implementation Patterns

### 5.1 Vim Keybindings

```php
// Source: TerminalRant keybindings
final class KeyMap {
    public const TIMELINE = [
        'j'          => 'next_post',
        'k'          => 'prev_post',
        'g'          => 'jump_top',
        'G'          => 'jump_bottom',
        'enter'      => 'open_detail',
        'o'          => 'open_in_browser',
        'r'          => 'refresh',
        'n'          => 'new_post',
        'c'          => 'reply',
        'l'          => 'like',
        'b'          => 'boost',
        'q'          => 'quit',
    ];

    public const COMPOSER = [
        'ctrl+enter' => 'send',
        'esc'        => 'cancel',
        'tab'        => 'next_field',
        'ctrl+c'     => 'force_quit',
    ];
}
```

### 5.2 Account Storage

```php
// Source: Mastui multi-profile approach
final class AccountStore {
    private string $baseDir;

    public function __construct(string $baseDir = '~/.config/sugar-post/accounts') {
        $this->baseDir = \expandPath($baseDir);
    }

    public function save(Account $account): void {
        $dir = "{$this->baseDir}/{$account->id}";
        \mkdir($dir, 0700, true);
        \file_put_contents("{$dir}/config.json", \json_encode($account, JSON_PRETTY_PRINT));
        // Store credentials separately with restricted permissions
        \chmod("{$dir}/config.json", 0600);
    }

    public function load(string $id): ?Account {
        $path = "{$this->baseDir}/{$id}/config.json";
        if (!\file_exists($path)) {
            return null;
        }
        return \json_decode(\file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    }

    public function list(): array {
        $accounts = [];
        foreach (\glob("{$this->baseDir}/*/config.json") as $path) {
            $accounts[] = $this->load(\basename(\dirname($path)));
        }
        return $accounts;
    }
}
```

### 5.3 Timeline Item Rendering

```php
// Source: Perch timeline design
final class TimelineRenderer {
    public function renderItem(Post $post, bool $selected = false): string {
        $cursor = $selected ? '▶ ' : '  ';
        $network = $this->networkBadge($post->network);
        $relativeTime = $this->relativeTime($post->createdAt);

        $preview = \mb_strlen($post->content) > 100
            ? \mb_substr($post->content, 0, 100) . '...'
            : $post->content;

        $stats = "🔁 {$post->reblogsCount}  ♥ {$post->favoritesCount}  💬 {$post->repliesCount}";

        return <<<ITEM
        {$cursor}{$post->author->username} · {$relativeTime} {$network}
        {$preview}
        {$stats}
        ITEM;
    }

    private function networkBadge(Network $network): string {
        return match($network) {
            Network::MASTODON => '[🐘 Mastodon]',
            Network::BLUESKY => '[🪼 Bluesky]',
            Network::TWITTER => '[🐦 X]',
        };
    }
}
```

### 5.4 OAuth Flow

```php
// Source: Unrager OAuth 2.0 PKCE pattern
final class OAuthHandler {
    public function initiate(MastodonInstance $instance): string {
        $verifier = \bin2hex(\random_bytes(32));
        $challenge = \base64_encode(\hash('sha256', $verifier, true));
        $challenge = \rtrim($challenge, '=');

        $_SESSION['oauth_verifier'] = $verifier;

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
            'response_type' => 'code',
            'scope' => 'read write follow',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        return $instance->url() . '/oauth/authorize?' . \http_build_query($params);
    }

    public function exchange(string $code, string $verifier): AccessToken {
        // Exchange code for access token with verifier
    }
}
```

---

## Part 6: Effort Estimates

| Feature | Complexity | Estimated Hours | Dependencies |
|---------|------------|-----------------|--------------|
| TUI framework setup | Medium | 8-12 | php-term/termbox or custom |
| Model/Update/View pattern | Medium | 4-6 | None |
| Vim keybindings | Low | 2-4 | None |
| Account management | High | 12-16 | Keychain integration |
| Timeline renderer | Medium | 8-10 | Async PHP |
| Post composer | Medium | 6-8 | None |
| Media upload | Medium | 4-6 | File handling |
| Markdown rendering | Low | 2-4 | sugar-glow |
| Real-time streaming | High | 10-14 | ReactPHP |
| Image rendering | High | 8-12 | Image libraries |
| Mastodon transport | Medium | 8-10 | HTTP client |
| OAuth flow | High | 6-8 | Browser integration |
| Scheduled posts | Medium | 4-6 | Cron/daemon |
| Multi-account | Medium | 6-8 | Account store |

---

## Part 7: Prioritized Recommendations

### High Priority (Implement First)

1. **Add TUI infrastructure** — Without this, sugar-post remains CLI-only
2. **Implement vim keybindings** — Standard expectation for Charm ecosystem ports
3. **Add Markdown body support** — Pop's signature feature
4. **Improve post composer** — Character counter, CW, visibility

### Medium Priority (Next Iteration)

5. **Add Mastodon API transport** — Enable actual social media usage
6. **Implement account management** — OAuth + secure storage
7. **Build timeline renderer** — Virtual scrolling with date grouping
8. **Add scheduled posts** — Queue posts for later delivery

### Lower Priority (Future)

9. **Real-time streaming** — SSE for live timeline updates
10. **Image rendering** — Kitty/iTerm2/Sixel protocols
11. **Cross-posting** — Post to multiple networks simultaneously
12. **Drafts system** — Save and edit incomplete posts

---

## Appendix A: Key References

| Tool | Repository | Key Patterns |
|------|------------|--------------|
| Pop (upstream) | https://github.com/charmbracelet/pop | TUI architecture |
| Perch | https://github.com/ricardodantas/perch | Multi-network, vim keys |
| Mastui | https://github.com/kimusan/mastui | Multi-column, Textual |
| Toot | https://github.com/ihabunek/toot | Account switching |
| Unrager | https://github.com/guitaripod/unrager | OAuth, LLM integration |
| Tootles | https://github.com/tootles-dev/tootles | Real-time streaming |
| Bubble Tea | https://github.com/charmbracelet/bubbletea | Model/Update/View |
| Textual | https://github.com/textualize/textual | Python TUI framework |

---

## Appendix B: PHP TUI Frameworks

| Framework | Status | Notes |
|-----------|--------|-------|
| **php-term/termbox** | Abandoned | Low-level terminal bindings |
| **bubbfwt/php-term-ui** | Active | PHP 8.1+, basic widgets |
| **jcuppf/php-terminal** | Active | Lightweight TUI |
| **Custom (recommended)** | N/A | Build on chord/candy-shell for key events |

**Recommendation:** Given SugarCraft already has `candy-shell` for terminal handling, build the TUI layer using existing components + ReactPHP for async rendering.

---

*Research completed by delegation agent. All sources cited with line numbers where applicable.*