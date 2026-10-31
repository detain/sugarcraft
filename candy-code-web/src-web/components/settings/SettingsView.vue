<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '../../lib/i18n'
import { useConnectionStore } from '../../stores/connection'
import { PUSH_PROBLEM_KEYS, usePushStore } from '../../stores/push'
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
  palette: 'web.settings.key_palette',
  help: 'web.settings.key_help_overlay',
  tabs: 'web.settings.key_tabs',
}
const keymapRows = ref(loadKeymap())
const capturing = ref<KeyAction | null>(null)
const keymapNote = ref('')

function startCapture(action: KeyAction): void {
  stopCapture()
  capturing.value = action
  keymapNote.value = t('web.settings.note_press', {label: t(KEYMAP_LABELS[action])})
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
  keymapNote.value = t('web.settings.note_clash', {first: t(KEYMAP_LABELS[action]), binding: bindingLabel(binding), second: t(KEYMAP_LABELS[clash])})
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
      ? t('web.settings.note_tab_chord')
      : t('web.settings.note_bad_chord')
    return
  }
  const clash = setBinding(action, binding)
  if (clash !== null) reportClash(action, clash, binding)
  else {
    keymapRows.value = loadKeymap()
    keymapNote.value = t('web.settings.note_bound', {label: t(KEYMAP_LABELS[action]), binding: bindingLabel(binding)})
  }
  stopCapture()
}

function resetOne(action: KeyAction): void {
  const clash = setBinding(action, DEFAULT_BINDINGS[action])
  if (clash !== null) reportClash(action, clash, DEFAULT_BINDINGS[action])
  else {
    keymapRows.value = loadKeymap()
    keymapNote.value = t('web.settings.note_defaulted', {label: t(KEYMAP_LABELS[action]), binding: bindingLabel(DEFAULT_BINDINGS[action])})
  }
}

function resetAll(): void {
  resetKeymap()
  keymapRows.value = loadKeymap()
  keymapNote.value = t('web.settings.note_reset_all')
}

/**
 * C-WEB-5.e: device push (roadmap WEB-8/GAP-WEB-10). The consent row beside
 * the keymap is the ONLY caller of the `push.*` RPCs; `stores/push.ts` keeps
 * the security laws (endpoint-as-bearer, no title opt-in, no prompt outside
 * a secure context). The row is hidden — not merely disabled — when the
 * runtime cannot honour it, mirroring the settings store's ISS-111 guard.
 */
const push = usePushStore()
const pushBusy = ref(false)

async function onPushChange(event: Event): Promise<void> {
  const on = (event.target as HTMLInputElement).checked
  pushBusy.value = true
  try {
    await push.setConsent(on)
  } finally {
    pushBusy.value = false
  }
}

onBeforeUnmount(stopCapture)
</script>

<template>
  <section class="settings" data-testid="settings-view" :data-loaded="settings.loaded ? 'true' : 'false'">
    <header class="top">
      <h1>{{ t('web.settings.title') }}</h1>
      <p class="lede">
        {{ t('web.settings.lede_a') }} <em>{{ t('web.settings.applies_live') }}</em>{{ t('web.settings.lede_b') }} <em>{{ t('web.settings.applies_next') }}</em>{{ t('web.settings.lede_c') }} <em>{{ t('web.settings.applies_restart') }}</em>{{ t('web.settings.lede_end') }}
      </p>
      <p v-if="!canAdmin && settings.loaded" class="warn" data-testid="settings-read-only">{{ t('web.settings.read_only') }}</p>

      <div class="bar">
        <fieldset class="tiers" :aria-label="t('web.settings.save_to_label')">
          <legend>{{ t('web.settings.save_to_label') }}</legend>
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
        <input v-model="filter" class="filter" type="search" :placeholder="t('web.settings.filter_placeholder')" :aria-label="t('web.settings.filter_aria')" data-testid="settings-filter">
      </div>
      <p v-if="settings.tier && !settings.tier.writable" class="muted">{{ settings.tier.refusal }}</p>
      <p v-else-if="settings.scope === 'project-shared'" class="warn" data-testid="settings-shared-warning">
        {{ t('web.settings.shared_warning') }}
      </p>
    </header>

    <p v-if="settings.error" class="error" role="alert" data-testid="settings-error">{{ settings.error }}</p>
    <p v-if="savedSummary" class="ok" role="status" data-testid="settings-saved">{{ savedSummary }}</p>
    <p v-if="!settings.loaded && settings.loading" class="muted">{{ t('web.settings.loading') }}</p>

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
        :reason="canAdmin ? settings.editabilityOf(row.key).reason : t('web.settings.reason_locked')"
        :staged="settings.staged[row.key]"
        @stage="(value) => settings.stage(row.key, value)"
        @reset="settings.stageReset(row.key)"
        @unstage="settings.unstage(row.key)"
      />
    </div>

    <details v-if="settings.files.length > 0" class="files">
      <summary>{{ t('web.settings.files_summary') }}</summary>
      <ul>
        <li v-for="file in settings.files" :key="file.path" data-testid="settings-file">
          <strong>{{ file.role }}</strong> <code>{{ file.path }}</code> — {{ file.status }}
          <div class="muted">{{ file.note }}</div>
        </li>
      </ul>
    </details>

    <div class="group" data-testid="keymap-section">
      <h2>{{ t('web.settings.keyboard_heading') }}</h2>
      <div class="keymap-body">
        <p class="muted">
          {{ t('web.settings.keymap_a') }} {{ t('web.settings.keymap_b') }}
        </p>
        <div v-for="action in KEY_ACTIONS" :key="action" class="keymap-row" data-testid="keymap-row" :data-action="action">
          <span class="what">{{ t(KEYMAP_LABELS[action]) }}</span>
          <code class="keys" data-testid="keymap-binding" :data-action="action">{{ bindingLabel(keymapRows[action]) }}</code>
          <button type="button" :data-testid="`keymap-rebind-${action}`" @click="capturing === action ? stopCapture() : startCapture(action)">
            {{ capturing === action ? t('web.settings.key_waiting') : t('web.settings.key_rebind') }}
          </button>
          <button type="button" :data-testid="`keymap-reset-${action}`" :disabled="bindingLabel(keymapRows[action]) === bindingLabel(DEFAULT_BINDINGS[action])" @click="resetOne(action)">
            {{ t('web.settings.key_default') }}
          </button>
        </div>
        <p v-if="keymapNote" class="ok" role="status" data-testid="keymap-note">{{ keymapNote }}</p>
        <button type="button" data-testid="keymap-reset-all" @click="resetAll()">{{ t('web.settings.key_reset_all') }}</button>
      </div>
    </div>

    <div v-if="push.visible" class="group" data-testid="push-section">
      <div class="push-body">
        <label class="push-row">
          <input type="checkbox" data-testid="push-toggle" :checked="push.consent" :disabled="pushBusy" @change="onPushChange($event)">
          {{ t('web.settings.push_label') }}
        </label>
        <p class="muted" data-testid="push-note">{{ t('web.settings.push_note') }}</p>
        <p v-if="push.problem" class="warn" role="status" data-testid="push-problem">{{ t(PUSH_PROBLEM_KEYS[push.problem]) }}</p>
      </div>
    </div>

    <footer v-if="settings.dirty" class="footer" data-testid="settings-footer">
      <span data-testid="settings-staged-count">{{ settings.stagedCount === 1 ? t('web.settings.staged_one', {n: settings.stagedCount, tier: tierLabel}) : t('web.settings.staged_other', {n: settings.stagedCount, tier: tierLabel}) }}</span>
      <span class="spacer" />
      <button type="button" data-testid="settings-discard" @click="settings.discard()">{{ t('web.settings.discard') }}</button>
      <button type="button" class="primary" :disabled="settings.previewing" data-testid="settings-preview-button" @click="settings.requestPreview()">{{ t('web.settings.preview_save') }}</button>
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
/* C-WEB-5.e: the consent row mirrors the keymap group's body layout. */
.push-body {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.5rem 0.75rem 0.75rem;
}
.push-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  font-size: 0.875rem;
}
.push-body .muted,
.push-body .warn {
  margin: 0;
}
</style>
