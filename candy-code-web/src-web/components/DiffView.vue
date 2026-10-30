<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getActivePinia } from 'pinia'
import { diffPathForHeader, parseUnifiedDiff, resolveDiffStyle, splitDiffRows, type DiffLine, type DiffSplitRow } from '../lib/diff'
import { highlight, languageForPath } from '../lib/highlight'
import { useSettingsStore, type DiffStyleChoice } from '../stores/settings'
import { readJson } from '../stores/storage'

const props = defineProps<{ diff: string }>()

// The pref lives in the settings store for the app; DiffView also mounts in
// pinia-less previews (the PermissionCard specs never install one), and those
// fall back to the same localStorage key read plainly — same value, same
// default, just not reactive, which a preview cannot change anyway.
const settings = getActivePinia() == null ? null : useSettingsStore()
const storedStyle = computed<DiffStyleChoice | string>(() => settings?.diffStyle ?? readJson<DiffStyleChoice>('diffStyle', 'auto'))

const lines = computed(() => parseUnifiedDiff(props.diff))

const widthPx = ref(typeof window === 'undefined' ? 0 : window.innerWidth)
function onResize(): void {
  widthPx.value = window.innerWidth
}
onMounted(() => window.addEventListener('resize', onResize))
onBeforeUnmount(() => window.removeEventListener('resize', onResize))

const split = computed(() => resolveDiffStyle(storedStyle.value, widthPx.value) === 'split')
const rows = computed(() => (split.value ? splitDiffRows(lines.value) : []))

// The language travels with the `--- `/`+++ `/`diff --git ` headers, mirroring
// candy-code's diffRowLanguages: a `diff --git` header replaces the current
// language (even with none — the new side is what the hunk below edits), a
// `--- `/`+++ ` line only updates it when it names a highlightable path.
const languageByLine = computed(() => {
  const byLine = new Map<DiffLine, string | null>()
  let current: string | null = null
  for (const line of lines.value) {
    if (line.kind === 'file') {
      const found = languageForPath(diffPathForHeader(line.text))
      current = line.text.startsWith('diff --git ') ? found : (found ?? current)
    }
    byLine.set(line, current)
  }
  return byLine
})

/** Body text as coloured HTML, or null to render it plain. highlight() escapes
 *  every source character before wrapping tokens, so a non-null answer is safe
 *  for v-html (same contract MessageMarkdown relies on). */
function cellHtml(line: DiffLine | null): string | null {
  if (line === null) return null
  if (line.kind !== 'add' && line.kind !== 'del' && line.kind !== 'ctx') return null
  const lang = languageByLine.value.get(line) ?? null
  return lang === null ? null : highlight(line.text, lang)
}

function halfKind(side: DiffLine | null): string {
  if (side === null) return 'empty'
  return side.kind
}

function rowClasses(row: DiffSplitRow): string[] {
  // Only span rows take kind classes on the row box: `.add`/`.del` colour the
  // whole row, which in split mode must stay a half-column colour.
  return row.type === 'span' ? ['pair', 'spanned', row.span.kind] : ['pair']
}
</script>

<template>
  <div class="diff" :class="{ split }" data-testid="diff" role="table" :aria-label="split ? 'diff, side by side' : 'diff'">
    <template v-if="!split">
      <div v-for="(line, i) in lines" :key="i" class="row" :class="line.kind" role="row">
        <span class="no" role="cell">{{ line.oldNo ?? '' }}</span>
        <span class="no" role="cell">{{ line.newNo ?? '' }}</span>
        <span class="sign" role="cell">{{ line.kind === 'add' ? '+' : line.kind === 'del' ? '−' : '' }}</span>
        <span class="text" role="cell"><span v-if="cellHtml(line) !== null" v-html="cellHtml(line)" /><template v-else>{{ line.text }}</template></span>
      </div>
    </template>
    <template v-else>
      <div v-for="(row, i) in rows" :key="i" class="row" :class="rowClasses(row)" role="row">
        <span v-if="row.type === 'span'" class="text" role="cell">{{ row.span.text }}</span>
        <template v-else>
          <span class="no" role="cell">{{ row.left?.oldNo ?? '' }}</span>
          <span class="half" :class="halfKind(row.left)" role="cell"><span v-if="cellHtml(row.left) !== null" v-html="cellHtml(row.left)" /><template v-else>{{ row.left?.text ?? '' }}</template></span>
          <span class="divider" role="cell" aria-hidden="true">│</span>
          <span class="no" role="cell">{{ row.right?.newNo ?? '' }}</span>
          <span class="half" :class="halfKind(row.right)" role="cell"><span v-if="cellHtml(row.right) !== null" v-html="cellHtml(row.right)" /><template v-else>{{ row.right?.text ?? '' }}</template></span>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
.diff {
  font-family: var(--mono);
  font-size: 0.8125rem;
  overflow-x: auto;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--panel);
}
/* Token tints for the spans cellHtml() injects via v-html. These nodes carry
   no data-v attribute (v-html is opaque to scoped CSS), so :deep() is
   mandatory — plain .hl-* selectors here would match nothing. The groups and
   variables mirror MessageMarkdown.vue's `.markdown :deep(.hl-*)` palette
   token-for-token; the two surfaces share the same tokens.css variables and a
   source-scan pin (diffView.spec.ts) fails if they ever drift apart. */
.diff :deep(.hl-comment) {
  color: var(--muted);
  font-style: italic;
}
.diff :deep(.hl-string) {
  color: var(--ok);
}
.diff :deep(.hl-number),
.diff :deep(.hl-literal) {
  color: var(--warn);
}
.diff :deep(.hl-keyword),
.diff :deep(.hl-tag) {
  color: var(--accent);
}
.diff :deep(.hl-variable),
.diff :deep(.hl-attr),
.diff :deep(.hl-key) {
  color: var(--error);
}
.diff :deep(.hl-meta) {
  color: var(--muted);
}
.diff :deep(.hl-add) {
  background: var(--diff-add);
}
.diff :deep(.hl-del) {
  background: var(--diff-del);
}
.row {
  display: grid;
  grid-template-columns: 3.5ch 3.5ch 2ch auto;
  white-space: pre;
  min-width: max-content;
}
/* Split halves wrap inside their column instead of scrolling the whole diff:
   the two halves of a row stay visually paired while the row box grows. */
.split .row {
  grid-template-columns: 4ch minmax(0, 1fr) 1.5ch 4ch minmax(0, 1fr);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  min-width: 0;
}
.split .row.spanned {
  grid-template-columns: auto;
}
.split .row.spanned .text {
  grid-column: 1 / -1;
}
.no {
  color: var(--muted);
  text-align: right;
  padding-right: 0.5ch;
  user-select: none;
}
.sign {
  text-align: center;
  user-select: none;
}
.divider {
  color: var(--line);
  text-align: center;
  user-select: none;
}
.add {
  background: var(--diff-add);
}
.del {
  background: var(--diff-del);
}
.split .half.add {
  background: var(--diff-add);
}
.split .half.del {
  background: var(--diff-del);
}
.split .half.empty {
  background: repeating-linear-gradient(135deg, transparent 0 6px, var(--line) 6px 7px);
  opacity: 0.35;
}
.hunk,
.file,
.meta {
  color: var(--muted);
}
.hunk .text,
.file .text,
.meta .text {
  grid-column: 1 / -1;
}
.hunk .no,
.hunk .sign,
.file .no,
.file .sign,
.meta .no,
.meta .sign {
  display: none;
}
</style>
