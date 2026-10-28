<script setup lang="ts">
import { computed } from 'vue'
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

const ACTION_LABELS: Record<KeyAction, string> = {
  palette: 'Command palette',
  help: 'This help',
  tabs: 'Pick the Nth open tab',
}

/** The composer's keys (Composer.vue) — fixed, unlike the keymap's three. */
const FIXED_KEYS: readonly [string, string][] = [
  ['Send · new line', 'Enter · Shift+Enter'],
  ['Complete commands · files', '/name · @path'],
  ['Prompt history · search', 'Ctrl+↑ / Ctrl+↓ · Ctrl+G'],
  ['Stop the running turn', 'Esc Esc'],
]

const shortcuts = computed(() => {
  const bindings = loadKeymap()
  return KEY_ACTIONS.map((action) => ({ action, label: ACTION_LABELS[action], keys: bindingLabel(bindings[action]) }))
})

function displayName(command: CommandInfo): string {
  return command.name.startsWith('/') ? command.name : `/${command.name}`
}
</script>

<template>
  <div v-if="open" class="backdrop" data-testid="help-backdrop" @click.self="emit('close')">
    <div class="dialog" role="dialog" aria-modal="true" aria-label="Keyboard help" tabindex="-1" data-testid="help-overlay">
      <header>
        <h2>Keyboard help</h2>
        <button type="button" class="link" aria-label="Close" data-testid="help-close" @click="emit('close')">&#10005;</button>
      </header>

      <h3>Shortcuts <span class="muted">(rebind in settings)</span></h3>
      <ul data-testid="help-shortcuts">
        <li v-for="row in shortcuts" :key="row.action" data-testid="help-shortcut" :data-action="row.action">
          <code class="keys">{{ row.keys }}</code>
          <span class="what">{{ row.label }}</span>
        </li>
      </ul>

      <h3>Composer keys</h3>
      <ul data-testid="help-fixed">
        <li v-for="row in FIXED_KEYS" :key="row[0]" data-testid="help-fixed-row">
          <code class="keys">{{ row[1] }}</code>
          <span class="what">{{ row[0] }}</span>
        </li>
      </ul>

      <h3>Commands</h3>
      <p v-if="commands.length === 0" class="muted" data-testid="help-no-commands">No commands listed — open a session and the server’s roster shows here.</p>
      <ul v-else data-testid="help-commands">
        <li v-for="command in commands" :key="command.name" data-testid="help-command" :data-runs-in="command.runsIn">
          <code class="cmd">{{ displayName(command) }}</code>
          <span v-if="command.argumentHint" class="hint">{{ command.argumentHint }}</span>
          <span class="desc">{{ command.description }}</span>
          <span class="runs" :class="command.runsIn" data-testid="help-runs-in">{{ command.runsIn === 'server' ? 'runs here' : 'terminal UI only' }}</span>
        </li>
      </ul>

      <p class="muted foot">Close with Esc, this backdrop, or the help key again.</p>
    </div>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: rgb(0 0 0 / 0.35);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 8vh 1rem 1rem;
}
.dialog {
  width: min(40rem, 100%);
  max-height: 80vh;
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
</style>
