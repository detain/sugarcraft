<script setup lang="ts">
import { computed } from 'vue'
import DiffView from '../DiffView.vue'
import { displayValue, type SavePreview } from '../../stores/settings/fields'

/**
 * What a settings save will do, shown before it does it (Appendix N §4.4):
 * the file, the diff of its JSON, when each change applies, what refuses the
 * save, and the precedence notes. "Saved" never implies "applied" — the apply
 * badges are part of the preview for that reason.
 */
const props = defineProps<{
  preview: SavePreview
  tierLabel: string
  saving: boolean
}>()

const emit = defineEmits<{
  save: []
  back: []
}>()

const refusals = computed(() => Object.entries(props.preview.refusals).map(([key, reason]) => ({ key, reason })))
</script>

<template>
  <section class="preview" data-testid="settings-preview" :data-can-save="preview.canSave ? 'true' : 'false'" aria-label="Save preview">
    <h2>Save to {{ tierLabel }}</h2>
    <p v-if="preview.path" class="path"><code>{{ preview.path }}</code></p>

    <ul v-if="refusals.length > 0" class="refusals" data-testid="settings-refusals">
      <li v-for="refusal in refusals" :key="refusal.key">&#10007; {{ refusal.reason }}</li>
    </ul>

    <ul class="changes">
      <li v-for="change in preview.changes" :key="change.key" data-testid="settings-change" :data-key="change.key">
        <code>{{ change.key }}</code>
        <template v-if="change.action === 'reset'"> reset (deleted from the file)</template>
        <template v-else> &rarr; {{ displayValue(change.value) }}</template>
        <span class="badge" :data-applies="change.applies">{{ change.appliesLabel }}</span>
      </li>
    </ul>

    <DiffView v-if="preview.diff !== ''" :diff="preview.diff" />
    <p v-else-if="refusals.length === 0" class="muted">No change to the file.</p>

    <p v-if="preview.applySummary" class="summary" data-testid="settings-apply-summary">Applies: {{ preview.applySummary }}</p>
    <ul v-if="preview.notes.length > 0" class="notes" data-testid="settings-notes">
      <li v-for="note in preview.notes" :key="note">! {{ note }}</li>
    </ul>

    <div class="actions">
      <button type="button" class="primary" :disabled="!preview.canSave || saving" data-testid="settings-save" @click="emit('save')">{{ saving ? 'Saving…' : 'Save' }}</button>
      <button type="button" :disabled="saving" data-testid="settings-back" @click="emit('back')">Back to editing</button>
    </div>
  </section>
</template>

<style scoped>
.preview {
  border: 1px solid var(--accent);
  border-radius: 8px;
  padding: 0.75rem 1rem;
  background: var(--panel);
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
h2 {
  margin: 0;
  font-size: 1rem;
}
.path code {
  font-family: var(--mono);
  font-size: 0.8125rem;
  color: var(--muted);
  overflow-wrap: anywhere;
}
.path,
.summary,
.muted {
  margin: 0;
}
ul {
  margin: 0;
  padding-left: 1.1rem;
}
.refusals {
  color: var(--error);
}
.notes {
  color: var(--warn);
}
.changes code {
  font-family: var(--mono);
}
.badge {
  margin-left: 0.4rem;
  font-size: 0.7rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  padding: 0 0.45rem;
  color: var(--muted);
}
.badge[data-applies='live'] {
  color: var(--ok);
  border-color: var(--ok);
}
.badge[data-applies='restart'],
.badge[data-applies='frozen'] {
  color: var(--warn);
  border-color: var(--warn);
}
.muted {
  color: var(--muted);
}
.actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
</style>
