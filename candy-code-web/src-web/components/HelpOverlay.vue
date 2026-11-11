<script setup lang="ts">
import { computed, ref } from 'vue'
import { t } from '../lib/i18n'
import { useDialog } from '../lib/useDialog'
import type { CommandInfo } from '../stores/session'
import { bindingLabel, KEY_ACTIONS, loadKeymap, type KeyAction } from '../lib/keymap'

/**
 * C-WEB-4.b (GAP-UI-28): the web's keyboard help. `?` on an empty composer
 * — or outside any field — opens it from `App.vue`; this is the listing:
 * the rebindable shortcuts as this viewer has them bound, the composer's
 * fixed keys, and the session's `command.list` rows with their `runsIn`
 * marker, so a terminal-UI-only command is visible as one instead of
 * failing on send.
 */
const props = defineProps<{ open: boolean; commands: CommandInfo[] }>()
const emit = defineEmits<{ close: [] }>()

const dialogEl = ref<HTMLElement | null>(null)
// CW-UX-07: Escape already closes from App.vue's global listener (not this
// component's to own); the spine adds focus-on-open, the Tab trap, and the
// restore to whatever opened the help.
const dialog = useDialog({ panel: dialogEl, open: () => props.open, handleEscape: false })

const ACTION_LABELS: Record<KeyAction, string> = {
  palette: 'web.chrome.help.action_palette',
  help: 'web.chrome.help.action_help',
  tabs: 'web.chrome.help.action_tabs',
}

/** The composer's keys (Composer.vue) — fixed, unlike the keymap's three. */
const FIXED_KEYS: readonly [string, string][] = [
  ['web.chrome.help.fixed_send', 'Enter · Shift+Enter'],
  ['web.chrome.help.fixed_complete', '/name · @path'],
  ['web.chrome.help.fixed_history', 'Ctrl+↑ / Ctrl+↓ · Ctrl+G'],
  ['web.chrome.help.fixed_stop', 'Esc Esc'],
]

/**
 * B-38 (CW-UX-B F16): keys the app really consumes that read as surprises —
 * each traced to its source. Composer.vue's completion popup steals Tab and
 * answers ↑/↓ (hi-jack noted as UXB-F8); with an empty draft Tab/→ accept the
 * ghost prompt once; PermissionCard.vue answers `y`/`a`/`n` on the focused
 * card (`a` only when Always is offered), and an AskUser question's choices
 * are numbered buttons answered by click (or focus + Enter) — never digits.
 */
const SURPRISE_KEYS: readonly [string, string][] = [
  ['web.chrome.help.surprise_cycle', '↑ / ↓'],
  ['web.chrome.help.surprise_accept', 'Tab'],
  ['web.chrome.help.surprise_ghost', 'Tab · →'],
  ['web.chrome.help.surprise_answer', 'y · a · n'],
  ['web.chrome.help.surprise_choices', '1. 2. 3.'],
]

const shortcuts = computed(() => {
  const bindings = loadKeymap()
  return KEY_ACTIONS.map((action) => ({ action, label: t(ACTION_LABELS[action]), keys: bindingLabel(bindings[action]) }))
})

function displayName(command: CommandInfo): string {
  return command.name.startsWith('/') ? command.name : `/${command.name}`
}
</script>

<template>
  <div v-if="open" class="backdrop" data-testid="help-backdrop" @click.self="emit('close')">
    <div ref="dialogEl" class="dialog" role="dialog" aria-modal="true" :aria-label="t('web.chrome.help.aria')" tabindex="-1" data-testid="help-overlay" @keydown="dialog.onKeydown">
      <header>
        <h2>{{ t('web.chrome.help.title') }}</h2>
        <button type="button" class="link" :aria-label="t('web.chrome.help.close_aria')" data-testid="help-close" @click="emit('close')">&#10005;</button>
      </header>

      <h3>{{ t('web.chrome.help.shortcuts') }} <span class="muted">{{ t('web.chrome.help.shortcuts_hint') }}</span></h3>
      <ul data-testid="help-shortcuts">
        <li v-for="row in shortcuts" :key="row.action" data-testid="help-shortcut" :data-action="row.action">
          <code class="keys">{{ row.keys }}</code>
          <span class="what">{{ row.label }}</span>
        </li>
      </ul>

      <h3>{{ t('web.chrome.help.composer_keys') }}</h3>
      <ul data-testid="help-fixed">
        <li v-for="row in FIXED_KEYS" :key="row[0]" data-testid="help-fixed-row">
          <code class="keys">{{ row[1] }}</code>
          <span class="what">{{ t(row[0]) }}</span>
        </li>
      </ul>

      <h3>{{ t('web.chrome.help.surprises') }}</h3>
      <ul data-testid="help-surprises">
        <li v-for="row in SURPRISE_KEYS" :key="row[0]" data-testid="help-surprise-row">
          <code class="keys">{{ row[1] }}</code>
          <span class="what">{{ t(row[0]) }}</span>
        </li>
      </ul>

      <h3>{{ t('web.chrome.help.commands') }}</h3>
      <p v-if="commands.length === 0" class="muted" data-testid="help-no-commands">{{ t('web.chrome.help.no_commands') }}</p>
      <ul v-else data-testid="help-commands">
        <li v-for="command in commands" :key="command.name" data-testid="help-command" :data-runs-in="command.runsIn">
          <code class="cmd">{{ displayName(command) }}</code>
          <span v-if="command.argumentHint" class="hint">{{ command.argumentHint }}</span>
          <span class="desc">{{ command.description }}</span>
          <span class="runs" :class="command.runsIn" data-testid="help-runs-in">{{ command.runsIn === 'server' ? t('web.chrome.help.command_runs_server') : t('web.chrome.help.command_runs_client') }}</span>
        </li>
      </ul>

      <p class="muted foot">{{ t('web.chrome.help.footer') }}</p>
    </div>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: var(--z-help);
  background: rgb(0 0 0 / 0.35);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 8vh 1rem 1rem;
}
.dialog {
  width: min(40rem, 100%);
  max-height: min(80vh, calc(100dvh - 2rem));
  overflow-y: auto;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 8px;
  box-shadow: 0 0.8rem 2rem rgb(0 0 0 / 0.4);
  padding: 0.75rem 1rem 1rem;
}
header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}
h2 {
  margin: 0;
  font-size: 1.1rem;
}
h3 {
  margin: 0.75rem 0 0.25rem;
  font-size: 0.8125rem;
  text-transform: uppercase;
  color: var(--muted);
}
ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
li {
  display: grid;
  grid-template-columns: 8.5rem 1fr;
  gap: 0.6rem;
  align-items: baseline;
  padding: 0.15rem 0;
  min-width: 0;
}
[data-testid='help-command'] {
  grid-template-columns: 8.5rem auto 1fr auto;
}
.keys {
  font-family: var(--mono);
  font-size: 0.8125rem;
  color: var(--accent);
  white-space: nowrap;
}
.cmd {
  font-family: var(--mono);
  font-size: 0.8125rem;
}
.what,
.desc {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.hint {
  color: var(--muted);
  font-size: 0.8125rem;
  white-space: nowrap;
}
.runs {
  font-size: 0.75rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  padding: 0 0.4rem;
  white-space: nowrap;
}
.runs.client {
  color: var(--warn);
}
.runs.server {
  color: var(--ok);
}
.muted {
  color: var(--muted);
  font-size: 0.875rem;
}
.foot {
  margin: 0.75rem 0 0;
}
.link {
  background: none;
  border: 0;
  color: var(--muted);
  font: inherit;
  cursor: pointer;
}
/* CW-UX-08: on short viewports the vh gutters eat the screen - collapse them
   to a constant and tighten the cap so the dialog's own scroll stays visible.
   UX-12: short — height axis of the shared scale (App.vue header). */
@media (max-height: 480px) {
  .backdrop {
    padding: 1rem;
  }
  .dialog {
    max-height: calc(100dvh - 1rem);
  }
}
</style>
