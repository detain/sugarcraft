# charmbracelet/soft-serve-action

## Metadata
- **URL:** https://github.com/charmbracelet/soft-serve-action
- **Language:** Go (main Soft Serve server) / GitHub Action YAML (this action wrapper)
- **Stars:** ~7K for main soft-serve repo (this action is a thin wrapper, likely few hundred)
- **License:** MIT (Copyright 2021-2023 Charmbracelet, Inc)
- **Description:** A GitHub Action for synchronizing GitHub repositories to your own [Soft Serve](https://github.com/charmbracelet/soft-serve) self-hostable Git server on every push.

## Feature List
- **Repository Mirroring:** Clone repositories as bare mirrors for exact synchronization
- **SSH Authentication:** Configure SSH keys for secure authentication to Soft Serve servers
- **SSH Known Hosts:** Automatically add Soft Serve server host keys to known_hosts
- **SSH Agent Management:** Start and configure SSH agent with provided credentials
- **Repository Naming:** Support custom repository names or use GitHub repository name
- **SSH User/Port Configuration:** Configurable SSH user (default: `git`) and port (default: `22`)
- **Bidirectional Sync:** Push all branches, tags with `--prune --force --all --follow-tags`
- **Mirror Mode:** Alternative `git push --mirror` for full repository synchronization

## Key Classes and Methods

This is **not a Go library** — it's a GitHub Action defined entirely in YAML with embedded bash scripts. There are no classes or Go methods. The "logic" consists of three bash script blocks:

- **id: mirror** — Conditional git mirror clone of the repository
- **id: add-ssh-key** — SSH agent setup, known_hosts population, and key configuration
- **id: push** — Remote addition and git push with configurable options

```yaml
# From action.yml:L32-L72
- id: mirror
  if: ${{ inputs.mirror == 'true' }}
  shell: bash
  run: |
    git clone --mirror $(git config --get remote.origin.url) mirror

- id: add-ssh-key
  shell: bash
  env:
    SSH_AUTH_SOCK: /tmp/ssh_agent.sock
  run: |
    mkdir -p ~/.ssh
    ssh-keyscan ${{ inputs.server }} >> ~/.ssh/known_hosts
    eval "$(ssh-agent -a $SSH_AUTH_SOCK)"
    if [ -n "${{ inputs.ssh-key }}" ]; then
      echo "${{ inputs.ssh-key }}" > ~/.ssh/soft_serve_id
      chmod 0600 ~/.ssh/soft_serve_id
      ssh-add ~/.ssh/soft_serve_id
    fi

- id: push
  shell: bash
  env:
    SSH_AUTH_SOCK: /tmp/ssh_agent.sock
    GIT_SSH_COMMAND: ssh -i $HOME/.ssh/soft_serve_id -o IdentitiesOnly=yes
  run: |
    NAME=${{ inputs.name }}
    if [ -z "$NAME" ]; then
      NAME=${{ env.REPOSITORY_NAME }}
    fi
    REMOTE=ssh://${{ inputs.ssh-user }}@${{ inputs.server }}:${{ inputs.ssh-port }}/$NAME
    if [ "${{ inputs.mirror }}" = "true" ]; then
      cd mirror
      git push --mirror $REMOTE
    else
      git remote add soft-serve $REMOTE
      git push --prune --force --all --follow-tags soft-serve
    fi
```

## Notable Algorithms / Named Patterns
- **SSH Agent Socket Pattern:** Uses `/tmp/ssh_agent.sock` for inter-process SSH agent communication
- **Known Hosts Accumulation:** `ssh-keyscan` appends to `~/.ssh/known_hosts` for host verification
- **Git Mirror Push:** `git clone --mirror` + `git push --mirror` for exact replica synchronization
- **Conditional Step Execution:** GitHub Actions `if:` conditions for mirror vs normal push modes
- **Environment Variable Propagation:** `$GITHUB_ENV` for setting `REPOSITORY_NAME` across steps
- **Composite Action Pattern:** Uses `runs: using: "composite"` to compose multiple bash steps

## Strengths
- **Minimal Footprint:** Single `action.yml` file, no compiled code or dependencies
- **Secure by Default:** Uses SSH with proper key permissions (`chmod 0600`) and known_hosts verification
- **Idempotent Push:** `--prune --force --all --follow-tags` ensures comprehensive synchronization
- **Authentication Flexibility:** Optional SSH key support with SSH agent integration
- **Soft Serve Integration:** Native integration with Charm's self-hostable Git server ecosystem
- **Configurable:** Supports custom server, user, port, repository name, and mirror mode
- **MIT Licensed:** Permissive open-source license

## Weaknesses
- **No Error Handling:** No explicit error checking in bash scripts (e.g., `set -e`, error codes)
- **Shallow Clone Problem:** README notes `fetch-depth: 0` required but no enforcement
- **Limited SSH Key Types:** Only supports basic SSH key file, no certificate-based auth
- **No Retry Logic:** Failed pushes do not retry
- **No Progress Feedback:** Silent failure modes if SSH or git operations fail
- **Not a Library:** Cannot be used as a Go library — this is a one-off GitHub Action
- **No Test Coverage:** No unit tests for the action logic itself (only release tagging workflow)

## SugarCraft Mapping

**This is NOT a library that can be ported to PHP.** This is a GitHub Action wrapper (YAML + bash scripts) for the Soft Serve Git server.

If mapping concepts to SugarCraft libs:

| Soft Serve Action Concern | SugarCraft Lib | Rationale |
|---|---|---|
| Git repository synchronization | `candy-core` (core Git operations) | Both deal with Git repo management and push operations |
| SSH authentication/agent | `candy-pty` (PTY/SSH wrappers) | SSH key handling and agent socket management |
| Mirroring repositories | `sugar-bits` (bits and pieces) | Repository clone/mirror operations conceptually similar |
| Self-hostable server | No direct mapping | SugarCraft is a client-side TUI library ecosystem |
| Repository push sync | No direct mapping | SugarCraft libs are local TUI, not server sync |

**Note:** SugarCraft ports the **Charmbracelet TUI ecosystem** (Bubble Tea, Glamour, etc.) to PHP. Soft Serve is a **server-side Git daemon** — fundamentally different from the TUI library ports. There is no meaningful SugarCraft port for this project.

If the goal were to port Soft Serve to PHP (not the action, but the server itself), it would require:
- A complete Git server implementation (wire protocols, SSH, HTTP, Git protocol)
- SSH daemon integration
- Repository storage and management
- Access control and authentication

This is entirely outside the scope of SugarCraft's mission to port Charm's **TUI libraries** to PHP.

## Analysis

The `charmbracelet/soft-serve-action` is a deceptively simple but well-crafted GitHub Action that bridges GitHub's cloud-hosted repositories with a self-hosted Soft Serve Git server. Despite being only 72 lines of YAML with embedded bash, it handles the full lifecycle: authenticating via SSH agent, configuring known hosts for secure connections, and pushing repositories with appropriate flags for complete synchronization.

The main Soft Serve project (7K stars) is a noteworthy Go-based Git server with a terminal UI accessible over SSH. It supports cloning over SSH, HTTP, or Git protocol, and allows on-demand repo creation. The soft-serve-action enables a continuous synchronization workflow where every push to GitHub automatically mirrors to your personal Soft Serve instance.

From an architecture perspective, this action demonstrates the composite action pattern effectively. It uses environment variables and bash environment to pass SSH agent sockets between steps, sets up git configuration properly, and handles the two push modes (mirror vs incremental) cleanly. The one notable gap is the absence of error handling — the action will fail silently if SSH connections fail or git push encounters issues. However, for the simplicity of the use case, this is probably acceptable.

This repository has no direct relationship to SugarCraft. SugarCraft ports the Charmbracelet TUI ecosystem (Bubble Tea, Wish, Glow, etc.) to PHP for terminal UI development. Soft Serve is a server-side Git hosting solution — a completely different domain. A researcher looking to map between these projects would find no meaningful overlap beyond being in the same Charmbracelet ecosystem organization on GitHub.
