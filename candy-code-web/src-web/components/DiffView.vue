<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getActivePinia } from 'pinia'
import { DIFF_SPLIT_MIN_PX, diffPathForHeader, parseUnifiedDiff, resolveDiffStyle, splitDiffRows, type DiffLine, type DiffSplitRow } from '../lib/diff'
import { highlight, languageForPath } from '../lib/highlight'
import { useSettingsStore, type DiffStyleChoice } from '../stores/settings'
import { readJson } from '../stores/storage'
import { t } from '../lib/i18n'

const props = defineProps<{ diff: string }>()

// The pref lives in the settings store for the app; DiffView also mounts in
// pinia-less previews (the PermissionCard specs never install one), and those
// fall back to the same localStorage key read plainly — same value, same
// default, just not reactive, which a preview cannot change anyway.
const settings = getActivePinia() == null ? null : useSettingsStore()
const storedStyle = computed<DiffStyleChoice | string>(() => settings?.diffStyle ?? readJson<DiffStyleChoice>('diffStyle', 'auto'))

const lines = computed(() => parseUnifiedDiff(props.diff))

const widthPx = ref(typeof window === 'undefined' ? 0 : window.innerWidth)
/**
 * MA-30: a plain `widthPx = window.innerWidth` resize handler repaints the whole diff on
 * EVERY drag frame, but the value only matters at exactly one point — the auto-mode
 * threshold. The handler therefore writes the ref only when a frame crosses
 * DIFF_SPLIT_MIN_PX (a ref assigned its same value triggers nothing), which is the
 * matchMedia-subscription behaviour without the MediaQueryList: the pinned resize-
 * dispatch specs below (diffView.spec.ts) drive real `resize` events, which a stubbed
 * happy-dom matchMedia would silently not deliver.
 */
function onResize(): void {
  const now = window.innerWidth
  if (now >= DIFF_SPLIT_MIN_PX !== (widthPx.value >= DIFF_SPLIT_MIN_PX)) widthPx.value = now
}
onMounted(() => window.addEventListener('resize', onResize))
onBeforeUnmount(() => window.removeEventListener('resize', onResize))

const split = computed(() => resolveDiffStyle(storedStyle.value, widthPx.value) === 'split')
const rowPairs = computed(() => (split.value ? splitDiffRows(lines.value) : []))

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

/* CW-10: content-addressed row identity. The index-keyed loops let a single
   inserted line swap every row's DOM below it — Vue patched text, syntax
   highlighting, and colour into what had been a different line. The walker
   stamps each line `hunk:offset:kind` (hunk counts from the first `@@`
   header, offset restarts per hunk), and both layouts key off it: split pair
   rows take the first present half — every line lands in exactly one row, so
   that choice is unique per row. */
const rowKeyByLine = computed(() => {
  const keys = new Map<DiffLine, string>()
  let hunk = -1
  let offset = 0
  for (const line of lines.value) {
    if (line.kind === 'hunk') {
      hunk += 1
      offset = 0
    }
    keys.set(line, `${hunk}:${offset}:${line.kind}`)
    offset += 1
  }
  return keys
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

function rowClasses(row: DiffSplitRow): string[] {
  // Only span rows take kind classes on the row box: `.add`/`.del` colour the
  // whole row, which in split mode must stay a half-column colour.
  return row.type === 'span' ? ['pair', 'spanned', row.span.kind] : ['pair']
}

/** One painted half: gutter number, class word, plain text, and the ONE highlight
 *  pass's HTML (null = render the text plain). */
interface SplitCell {
  readonly kind: string
  readonly no: string
  readonly text: string
  readonly html: string | null
}

const BLANK_CELL: SplitCell = { kind: 'empty', no: '', text: '', html: null }

/** A pre-painted row of either layout: the highlight walk runs ONCE per line here,
 *  not once per template call site per render (MA-30 — the old shape called
 *  `cellHtml()` twice per cell from v-if+v-html, on every reactive re-render). */
interface DiffRowModel {
  readonly key: string
  readonly kind: string
  readonly oldNo: string
  readonly newNo: string
  readonly sign: string
  readonly text: string
  readonly html: string | null
  readonly classes: readonly string[]
  readonly spanText: string | null
  readonly left: SplitCell
  readonly right: SplitCell
}

function halfCell(line: DiffLine | null, which: 'oldNo' | 'newNo'): SplitCell {
  if (line === null) return BLANK_CELL
  return { kind: line.kind, no: line[which] === null ? '' : String(line[which]), text: line.text, html: cellHtml(line) }
}

const unifiedModel = computed<DiffRowModel[]>(() => {
  const keys = rowKeyByLine.value
  return lines.value.map((line) => ({
    key: keys.get(line)!,
    kind: line.kind,
    oldNo: line.oldNo === null ? '' : String(line.oldNo),
    newNo: line.newNo === null ? '' : String(line.newNo),
    sign: line.kind === 'add' ? '+' : line.kind === 'del' ? '−' : '',
    text: line.text,
    html: cellHtml(line),
    classes: [line.kind],
    spanText: null,
    left: BLANK_CELL,
    right: BLANK_CELL,
  }))
})

const splitModel = computed<DiffRowModel[]>(() => {
  const keys = rowKeyByLine.value
  return rowPairs.value.map((row) =>
    row.type === 'span'
      ? { key: keys.get(row.span)!, kind: row.span.kind, oldNo: '', newNo: '', sign: '', text: '', html: null, classes: rowClasses(row), spanText: row.span.text, left: BLANK_CELL, right: BLANK_CELL }
      : { key: `${keys.get((row.left ?? row.right)!)!}:pair`, kind: 'pair', oldNo: '', newNo: '', sign: '', text: '', html: null, classes: rowClasses(row), spanText: null, left: halfCell(row.left, 'oldNo'), right: halfCell(row.right, 'newNo') },
  )
})

/** MA-30 render budget: a 5,000-line session diff must not stamp 5,000 painted rows onto
 *  the DOM at once. Each press of the release adds another budget; a NEW diff re-arms it
 *  (an expanding review while typing must not carry an old giant budget forward). */
const DIFF_ROW_CAP = 500
const rowBudget = ref(DIFF_ROW_CAP)
watch(
  () => props.diff,
  () => {
    rowBudget.value = DIFF_ROW_CAP
  },
)

const painted = computed(() => (split.value ? splitModel.value : unifiedModel.value))
const shownRows = computed(() => painted.value.slice(0, rowBudget.value))
const hiddenRows = computed(() => Math.max(0, painted.value.length - rowBudget.value))
function showMoreRows(): void {
  rowBudget.value += DIFF_ROW_CAP
}
</script>

<template>
  <!-- CW-UXB-F2/F3: the box is a plain focusable scroller (wide unified rows
       overflow-x); the table semantics move to a rows wrapper so the show-more
       line below is no longer a role="status" child inside a role="table" —
       invalid structure that ATs dropped on >500-row diffs. Box, border and
       scroll are byte-identical; only the ARIA parentage changed. -->
  <div class="diff" :class="{ split }" data-testid="diff" tabindex="0">
    <div class="rows" role="table" :aria-label="split ? t('web.transcript.diff_split_aria') : t('web.transcript.diff_aria')">
      <template v-if="!split">
        <div v-for="row in shownRows" :key="row.key" class="row" :class="row.kind" role="row">
          <span class="no numbers" role="cell">{{ row.oldNo }}</span>
          <span class="no numbers" role="cell">{{ row.newNo }}</span>
          <span class="sign" role="cell">{{ row.sign }}</span>
          <span class="text" role="cell"><span v-if="row.html !== null" v-html="row.html" /><template v-else>{{ row.text }}</template></span>
        </div>
      </template>
      <template v-else>
        <div v-for="row in shownRows" :key="row.key" class="row" :class="row.classes" role="row">
          <span v-if="row.spanText !== null" class="text" role="cell">{{ row.spanText }}</span>
          <template v-else>
            <span class="no numbers" role="cell">{{ row.left.no }}</span>
            <span class="half" :class="row.left.kind" role="cell"><span v-if="row.left.html !== null" v-html="row.left.html" /><template v-else>{{ row.left.text }}</template></span>
            <span class="divider" role="cell" aria-hidden="true">│</span>
            <span class="no numbers" role="cell">{{ row.right.no }}</span>
            <span class="half" :class="row.right.kind" role="cell"><span v-if="row.right.html !== null" v-html="row.right.html" /><template v-else>{{ row.right.text }}</template></span>
          </template>
        </div>
      </template>
    </div>
    <p v-if="hiddenRows > 0" class="rest" role="status" data-testid="diff-more">
      <button type="button" class="link" data-testid="diff-show-more" @click="showMoreRows">{{ t('web.transcript.file_load_more') }}</button>
      <span>{{ hiddenRows }}</span>
    </p>
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
/* MA-30: the show-more affordance under the row cap — one quiet line, the
   hidden count as plain text beside it (no new catalog key: the button label
   reuses web.transcript.file_load_more, same idiom as FileViewer's tail). */
.rest {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.25rem 0.5rem;
  margin: 0;
  border-top: 1px solid var(--line);
  color: var(--muted);
  font-size: 0.75rem;
}
.numbers {
  font-variant-numeric: tabular-nums;
}
</style>
