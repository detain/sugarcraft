<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useConnectionStore } from '../../stores/connection'
import { groupRows } from '../../stores/settings/fields'
import { useServerSettingsStore } from '../../stores/settings/serverSettings'
import {
  beginKeyCapture,
  bindingFromEvent,
  bindingLabel,
  DEFAULT_BINDINGS,
  endKeyCapture,
  KEY_ACTIONS,
  loadKeymap,
  resetKeymap,
  setBinding,
  type KeyAction,
  type KeyBinding,
} from '../../lib/keymap'
import SettingsField from './SettingsField.vue'
import SettingsPreview from './SettingsPreview.vue'

/**
 * The server's settings page (roadmap O-6b; Appendix O §7.4 "Settings
 * view"): a form generated from `settings.schema` — grouped as the TUI editor
 * groups it, each field with its apply-mode badge, its provenance, and why it
 * is read-only when it is — an edit set staged against one tier, previewed as
 * the target file's diff, and saved in one write.
 */
const connection = useConnectionStore()
const settings = useServerSettingsStore()
const filter = ref('')

const groups = computed(() => groupRows(settings.rows, filter.value))
const canAdmin = computed(() => connection.scopes.includes('admin'))
const tierLabel = computed(() => settings.tier?.label ?? settings.scope)
const savedSummary = computed(() => {
  const saved = settings.saved
  if (saved === null) return ''
  const counts = new Map<string, number>()
  for (const mode of Object.values(saved.appliesByKey ?? {})) counts.set(mode, (counts.get(mode) ?? 0) + 1)
  const parts = [...counts].map(([mode, n]) => `${n} ${mode.replace('-', ' ')}`)
  const n = saved.changed.length
  return `Saved ${n} setting${n === 1 ? '' : 's'} to ${saved.written}${parts.length > 0 ? ' · ' + parts.join(' · ') : ''}`
})

function load(): void {
  if (connection.connected && !settings.loading) void settings.load()
}

onMounted(load)
watch(() => connection.connected, (connected) => {
  if (connected && !settings.loaded) load()
})

/**
 * C-WEB-4.b: the three client-side shortcuts (`lib/keymap`), saved per
 * viewer through `stores/storage` — deliberately apart from the server's
 * schema above. Rebinding listens in the capture phase with the global
 * handlers held off by `beginKeyCapture()`; a chord that two actions would
 * answer is refused, never silently shadowed.
 */
const KEYMAP_LABELS: Record<KeyAction, string> = {
  palette: 'Command palette',
  help: 'Help overlay',
  tabs: 'Pick the Nth open tab',
}
const keymapRows = ref(loadKeymap())
const capturing = ref<KeyAction | null>(null)
const keymapNote = ref('')

function startCapture(action: KeyAction): void {
  stopCapture()
  capturing.value = action
  keymapNote.value = `Press the new chord for ${KEYMAP_LABELS[action]}… (Esc keeps the current one)`
  beginKeyCapture()
  window.addEventListener('keydown', onCaptureKey, true)
}

function stopCapture(): void {
  if (capturing.value === null) return
  window.removeEventListener('keydown', onCaptureKey, true)
  capturing.value = null
  endKeyCapture()
}

function reportClash(action: KeyAction, clash: KeyAction, binding: KeyBinding): void {
  keymapRows.value = loadKeymap()
  keymapNote.value = `Not ${KEYMAP_LABELS[action]} — ${bindingLabel(binding)} is already the ${KEYMAP_LABELS[clash]} key.`
}

function onCaptureKey(event: KeyboardEvent): void {
  const action = capturing.value
  if (action === null) return
  event.preventDefault()
  event.stopPropagation()
  if (event.key === 'Escape') {
    stopCapture()
    keymapNote.value = ''
    return
  }
  const binding = bindingFromEvent(event, action === 'tabs' ? 'chord' : 'key')
  if (binding === null) {
    keymapNote.value = action === 'tabs'
      ? 'The tab shortcut stays an Alt-family chord over a digit — press Alt, Alt+Shift or Ctrl+Alt with a number.'
      : 'Not a bindable chord — press a letter, digit or F-key with its modifiers.'
    return
  }
  const clash = setBinding(action, binding)
  if (clash !== null) reportClash(action, clash, binding)
  else {
    keymapRows.value = loadKeymap()
    keymapNote.value = `${KEYMAP_LABELS[action]} → ${bindingLabel(binding)}.`
  }
  stopCapture()
}

function resetOne(action: KeyAction): void {
  const clash = setBinding(action, DEFAULT_BINDINGS[action])
  if (clash !== null) reportClash(action, clash, DEFAULT_BINDINGS[action])
  else {
    keymapRows.value = loadKeymap()
    keymapNote.value = `${KEYMAP_LABELS[action]} → ${bindingLabel(DEFAULT_BINDINGS[action])} (default).`
  }
}

function resetAll(): void {
  resetKeymap()
  keymapRows.value = loadKeymap()
  keymapNote.value = 'Shortcuts reset to their defaults.'
}

onBeforeUnmount(stopCapture)
</script>

<template>
  <section class="settings" data-testid="settings-view" :data-loaded="settings.loaded ? 'true' : 'false'">
    <header class="top">
      <h1>Settings</h1>
      <p class="lede">
        The server's settings, from the same schema as the terminal UI's settings editor. A saved change applies as its
        badge says — <em>live</em>, from the <em>next turn</em>, or after a <em>restart</em>.
      </p>
      <p v-if="!canAdmin && settings.loaded" class="warn" data-testid="settings-read-only">This sign-in can read settings but not change them.</p>

      <div class="bar">
        <fieldset class="tiers" aria-label="Save to">
          <legend>Save to</legend>
          <label
            v-for="tier in settings.tiers"
            :key="tier.scope"
            class="tier"
            :class="{ off: !tier.writable }"
            :title="tier.writable ? tier.path : tier.refusal"
            :data-testid="`settings-tier-${tier.scope}`"
          >
            <input type="radio" name="tier" :value="tier.scope" :checked="settings.scope === tier.scope" :disabled="!tier.writable" @change="settings.setScope(tier.scope)">
            {{ tier.label }}
          </label>
        </fieldset>
        <input v-model="filter" class="filter" type="search" placeholder="Filter settings…" aria-label="Filter settings" data-testid="settings-filter">
      </div>
      <p v-if="settings.tier && !settings.tier.writable" class="muted">{{ settings.tier.refusal }}</p>
      <p v-else-if="settings.scope === 'project-shared'" class="warn" data-testid="settings-shared-warning">
        This project's shared settings file is committed: everyone who clones the repository and trusts it gets what you save here.
      </p>
    </header>

    <p v-if="settings.error" class="error" role="alert" data-testid="settings-error">{{ settings.error }}</p>
    <p v-if="savedSummary" class="ok" role="status" data-testid="settings-saved">{{ savedSummary }}</p>
    <p v-if="!settings.loaded && settings.loading" class="muted">Loading settings…</p>

    <SettingsPreview
      v-if="settings.preview"
      :preview="settings.preview"
      :tier-label="tierLabel"
      :saving="settings.saving"
      @save="settings.save()"
      @back="settings.closePreview()"
    />

    <div v-for="group in groups" :key="group.group" class="group" data-testid="settings-group" :data-group="group.group">
      <h2>{{ group.group }}</h2>
      <SettingsField
        v-for="row in group.rows"
        :key="row.key"
        :row="row"
        :effective="settings.values[row.key]"
        :editable="canAdmin && settings.editabilityOf(row.key).editable"
        :reason="canAdmin ? settings.editabilityOf(row.key).reason : 'this sign-in may not change settings'"
        :staged="settings.staged[row.key]"
        @stage="(value) => settings.stage(row.key, value)"
        @reset="settings.stageReset(row.key)"
        @unstage="settings.unstage(row.key)"
      />
    </div>

    <details v-if="settings.files.length > 0" class="files">
      <summary>Where these values come from</summary>
      <ul>
        <li v-for="file in settings.files" :key="file.path" data-testid="settings-file">
          <strong>{{ file.role }}</strong> <code>{{ file.path }}</code> — {{ file.status }}
          <div class="muted">{{ file.note }}</div>
        </li>
      </ul>
    </details>

    <div class="group" data-testid="keymap-section">
      <h2>Keyboard — this browser</h2>
      <div class="keymap-body">
        <p class="muted">
          The web client's own shortcuts, saved per viewer on this device — nothing here touches the server.
          `?` opens the help overlay when the composer is empty or focus is outside any field.
        </p>
        <div v-for="action in KEY_ACTIONS" :key="action" class="keymap-row" data-testid="keymap-row" :data-action="action">
          <span class="what">{{ KEYMAP_LABELS[action] }}</span>
          <code class="keys" data-testid="keymap-binding" :data-action="action">{{ bindingLabel(keymapRows[action]) }}</code>
          <button type="button" :data-testid="`keymap-rebind-${action}`" @click="capturing === action ? stopCapture() : startCapture(action)">
            {{ capturing === action ? 'Waiting… (Esc)' : 'Rebind' }}
          </button>
          <button type="button" :data-testid="`keymap-reset-${action}`" :disabled="bindingLabel(keymapRows[action]) === bindingLabel(DEFAULT_BINDINGS[action])" @click="resetOne(action)">
            Default
          </button>
        </div>
        <p v-if="keymapNote" class="ok" role="status" data-testid="keymap-note">{{ keymapNote }}</p>
        <button type="button" data-testid="keymap-reset-all" @click="resetAll()">Reset all three to defaults</button>
      </div>
    </div>

    <footer v-if="settings.dirty" class="footer" data-testid="settings-footer">
      <span data-testid="settings-staged-count">{{ settings.stagedCount }} change{{ settings.stagedCount === 1 ? '' : 's' }} staged for {{ tierLabel }}</span>
      <span class="spacer" />
      <button type="button" data-testid="settings-discard" @click="settings.discard()">Discard</button>
      <button type="button" class="primary" :disabled="settings.previewing" data-testid="settings-preview-button" @click="settings.requestPreview()">Preview save</button>
    </footer>
  </section>
</template>

<style scoped>
.settings {
  padding: 1rem;
  max-width: 56rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
h1 {
  margin: 0;
  font-size: 1.25rem;
}
.lede,
.muted,
.warn,
.ok,
.error {
  margin: 0;
  font-size: 0.875rem;
}
.lede,
.muted {
  color: var(--muted);
}
.warn {
  color: var(--warn);
}
.ok {
  color: var(--ok);
  overflow-wrap: anywhere;
}
.error {
  color: var(--error);
}
.top {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}
.tiers {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 0.25rem 0.75rem;
  margin: 0;
}
.tiers legend {
  font-size: 0.75rem;
  color: var(--muted);
}
.tier {
  font-size: 0.875rem;
  white-space: nowrap;
}
.tier.off {
  color: var(--muted);
}
.filter {
  font: inherit;
  font-size: 0.875rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 0.3rem 0.6rem;
  flex: 1;
  min-width: 10rem;
}
.group {
  border: 1px solid var(--line);
  border-radius: 8px;
  overflow: hidden;
}
.group h2 {
  margin: 0;
  padding: 0.4rem 0.75rem;
  font-size: 0.875rem;
  background: var(--panel);
  border-bottom: 1px solid var(--line);
}
.files {
  font-size: 0.8125rem;
}
.files ul {
  padding-left: 1.1rem;
}
.files code {
  font-family: var(--mono);
  overflow-wrap: anywhere;
}
.footer {
  position: sticky;
  bottom: 0;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: var(--panel);
  border: 1px solid var(--accent);
  border-radius: 8px;
}
.spacer {
  flex: 1;
}
.keymap-body {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.5rem 0.75rem 0.75rem;
}
.keymap-row {
  display: grid;
  grid-template-columns: 1fr 8rem auto auto;
  gap: 0.5rem;
  align-items: center;
}
.keymap-row .keys {
  font-family: var(--mono);
  color: var(--accent);
  white-space: nowrap;
}
.keymap-body .muted,
.keymap-body .ok {
  margin: 0;
}
</style>
