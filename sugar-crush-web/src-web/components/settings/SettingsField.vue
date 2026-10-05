<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  appliesLabel,
  choices,
  displayValue,
  fieldKind,
  inputText,
  parseInput,
  provenance,
  type EffectiveValue,
  type SettingRow,
  type Staged,
} from '../../stores/settings/fields'

/**
 * One setting in the web settings form, drawn from its `settings.schema` row:
 * the control its type asks for, when a change applies, where the current
 * value comes from, and — when it may not be edited here — why.
 */
const props = defineProps<{
  row: SettingRow
  effective: EffectiveValue | undefined
  editable: boolean
  reason: string | null
  staged: Staged | undefined
}>()

const emit = defineEmits<{
  stage: [value: unknown]
  reset: []
  unstage: []
}>()

const kind = computed(() => fieldKind(props.row))
const options = computed(() => choices(props.row))
const current = computed(() => (props.staged?.action === 'set' ? props.staged.value : props.effective?.value))
const draft = ref('')
const fieldError = ref<string | null>(null)
const inputId = computed(() => `setting-${props.row.key.replace(/[^A-Za-z0-9_-]/g, '-')}`)
const source = computed(() => provenance(props.effective))
const isDefault = computed(() => (props.effective?.source ?? 'default') === 'default')

watch(current, (value) => {
  draft.value = inputText(props.row, value)
  fieldError.value = null
}, { immediate: true })

function commit(raw: string | boolean): void {
  const parsed = parseInput(props.row, raw)
  if (!parsed.ok) {
    fieldError.value = parsed.error
    return
  }
  fieldError.value = null
  emit('stage', parsed.value)
}

function onCheckbox(event: Event): void {
  commit((event.target as HTMLInputElement).checked)
}
</script>

<template>
  <div
    class="field"
    :class="{ staged: staged !== undefined, locked: !editable }"
    data-testid="settings-field"
    :data-key="row.key"
    :data-editable="editable ? 'true' : 'false'"
    :data-source="effective?.source ?? 'default'"
    :data-applies="row.applies"
  >
    <div class="head">
      <label :for="inputId" class="label">{{ row.label }}</label>
      <code class="key">{{ row.key }}</code>
      <span class="badge applies" :data-applies="row.applies" :title="`A saved change applies: ${appliesLabel(row)}`" data-testid="settings-applies">{{ appliesLabel(row) }}</span>
      <span class="badge source" :class="{ lock: effective?.locked }" :title="effective?.sourcePath ?? source" data-testid="settings-source">
        <template v-if="effective?.locked">&#128274; </template>{{ isDefault ? 'default' : source }}
      </span>
    </div>
    <p v-if="row.help" class="help">{{ row.help }}</p>

    <div class="control">
      <input
        v-if="kind === 'bool'"
        :id="inputId"
        type="checkbox"
        :checked="current === true"
        :disabled="!editable"
        data-testid="settings-input"
        @change="onCheckbox"
      >
      <select
        v-else-if="kind === 'select'"
        :id="inputId"
        v-model="draft"
        :disabled="!editable"
        data-testid="settings-input"
        @change="commit(draft)"
      >
        <option v-if="current === undefined || current === null" value="" disabled>unset</option>
        <option v-for="option in options" :key="option" :value="option">{{ option }}</option>
      </select>
      <textarea
        v-else-if="kind === 'list' || kind === 'json'"
        :id="inputId"
        v-model="draft"
        :rows="kind === 'json' ? 4 : 3"
        :disabled="!editable"
        :placeholder="kind === 'list' ? 'one per line' : 'JSON'"
        spellcheck="false"
        data-testid="settings-input"
        @change="commit(draft)"
      />
      <input
        v-else
        :id="inputId"
        v-model="draft"
        :type="kind === 'number' ? 'number' : 'text'"
        :min="row.min"
        :max="row.max"
        :step="row.type === 'int' ? 1 : 'any'"
        :placeholder="row.defaultText ?? (row.default !== undefined ? displayValue(row.default) : 'unset')"
        :disabled="!editable"
        data-testid="settings-input"
        @change="commit(draft)"
        @keydown.enter="commit(draft)"
      >

      <template v-if="editable">
        <button v-if="staged !== undefined" type="button" class="link" data-testid="settings-unstage" @click="emit('unstage')">undo</button>
        <button v-else-if="!isDefault" type="button" class="link" title="Delete the key from the file, so the default (or a lower layer) applies" data-testid="settings-reset" @click="emit('reset')">reset</button>
      </template>
    </div>

    <p v-if="fieldError" class="error" role="alert" data-testid="settings-field-error">{{ fieldError }}</p>
    <p v-if="staged?.action === 'reset'" class="note" data-testid="settings-staged">reset staged — the save deletes it</p>
    <p v-else-if="staged" class="note" data-testid="settings-staged">changed from {{ displayValue(effective?.value) }}</p>
    <p v-if="!editable && reason" class="reason" data-testid="settings-reason">{{ reason }}</p>
  </div>
</template>

<style scoped>
.field {
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid var(--line);
}
.field.staged {
  border-left: 3px solid var(--accent);
}
.head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.4rem 0.6rem;
}
.label {
  font-weight: 600;
}
.key {
  font-family: var(--mono);
  font-size: 0.75rem;
  color: var(--muted);
}
.badge {
  font-size: 0.7rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  padding: 0 0.45rem;
  color: var(--muted);
  white-space: nowrap;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
}
.applies[data-applies='live'] {
  color: var(--ok);
  border-color: var(--ok);
}
.applies[data-applies='restart'],
.applies[data-applies='frozen'] {
  color: var(--warn);
  border-color: var(--warn);
}
.source.lock {
  color: var(--warn);
}
.help {
  margin: 0.25rem 0 0;
  font-size: 0.8125rem;
  color: var(--muted);
}
.control {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin-top: 0.4rem;
}
.control input[type='text'],
.control input[type='number'],
.control select,
.control textarea {
  font: inherit;
  font-size: 0.875rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 0.25rem 0.5rem;
  min-width: 0;
  width: min(100%, 24rem);
}
.control textarea {
  font-family: var(--mono);
  width: min(100%, 32rem);
}
.control :disabled {
  opacity: 0.6;
}
.error {
  color: var(--error);
  font-size: 0.8125rem;
  margin: 0.25rem 0 0;
}
.note {
  color: var(--accent);
  font-size: 0.8125rem;
  margin: 0.25rem 0 0;
}
.reason {
  color: var(--muted);
  font-size: 0.8125rem;
  font-style: italic;
  margin: 0.25rem 0 0;
}
</style>
