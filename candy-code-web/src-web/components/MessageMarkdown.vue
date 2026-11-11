<script setup lang="ts">
import { computed, onMounted, onUpdated, ref } from 'vue'
import { renderMarkdown } from '../lib/markdown'
import { highlightIn } from '../lib/highlight'
import { t } from '../lib/i18n'

const props = defineProps<{ text: string }>()
// Sanitised by renderMarkdown (DOMPurify) before it reaches v-html.
const html = computed(() => renderMarkdown(props.text))
const root = ref<HTMLElement | null>(null)

// The colouring module rides the eager chunk (DiffView pulls it in at load),
// so this is a synchronous walk that no-ops when nothing matches. The old
// dynamic import() was vite-flagged INEFFECTIVE_DYNAMIC_IMPORT: it never
// moved the module into its own chunk (B-78).
function colour(): void {
  const element = root.value
  if (element === null) return
  highlightIn(element)
}

/**
 * C-TUI-3.b: one copy button per fenced block. The buttons cannot ride the
 * sanitised HTML — DOMPurify's FORBID_TAGS drops `button` (the injection
 * surface is exactly the point of that list) — so this pass appends real DOM
 * nodes after each v-html render, skipping blocks that already carry one.
 * The clipboard needs a secure context (the notifications precedent,
 * `stores/settings.ts`), so outside one no buttons appear at all. The text
 * is read from the code element at click time: a tokenised block's
 * textContent is unchanged by the colouring pass, and an edit that lands
 * between paint and click copies what is on screen.
 *
 * The walk keys on each `pre`, not on the `.code-block` wrapper: sanitising
 * a fragment whose first element is the wrapper drops the wrapper but keeps
 * its children under some DOM implementations (happy-dom under vitest,
 * reproducibly), so a wrapper-keyed pass would silently decorate nothing in
 * those tests. In a browser the wrapper survives and `closest()` returns it,
 * so the button lands on the block box — which (CW-UX-23) lays it out as a
 * flex column member BELOW the horizontally scrollable `pre`, never floated
 * over its code.
 */
function decorate(): void {
  const element = root.value
  if (element === null) return
  if (typeof window !== 'undefined' && window.isSecureContext === false) return
  for (const pre of Array.from(element.querySelectorAll<HTMLElement>('pre'))) {
    const code = pre.querySelector('code')
    if (code === null) continue
    const block = pre.closest<HTMLElement>('.code-block') ?? pre
    if (block.querySelector('.code-copy') !== null) continue
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'code-copy'
    button.setAttribute('data-testid', 'copy-code-block')
    button.textContent = t('web.transcript.copy_idle')
    button.title = t('web.transcript.copy_code_title')
    button.addEventListener('click', () => {
      const text = (code.textContent ?? '').replace(/\n$/, '')
      if (typeof navigator === 'undefined' || !navigator.clipboard) {
        button.textContent = t('web.transcript.copy_failed')
        return
      }
      navigator.clipboard.writeText(text).then(
        () => { button.textContent = t('web.transcript.copy_copied') },
        () => { button.textContent = t('web.transcript.copy_failed') },
      )
    })
    block.appendChild(button)
  }
}

function paint(): void {
  colour()
  decorate()
}

onMounted(paint)
onUpdated(paint)
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -->
  <div ref="root" class="markdown" v-html="html" />
</template>

<style scoped>
.markdown {
  line-height: 1.55;
  overflow-wrap: anywhere;
}
.markdown :deep(p) {
  margin: 0 0 0.6rem;
}
.markdown :deep(p:last-child) {
  margin-bottom: 0;
}
.markdown :deep(blockquote) {
  margin: 0 0 0.6rem;
  padding: 0.1rem 0 0.1rem 0.75rem;
  border-left: 3px solid var(--line);
  color: var(--muted);
}
.markdown :deep(code) {
  font-family: var(--mono);
  font-size: 0.875em;
  background: var(--panel);
  padding: 0.1rem 0.3rem;
  border-radius: 4px;
}
.markdown :deep(.code-block) {
  position: relative;
  /* CW-UX-23: a flex column, so the injected copy button flows UNDER the
     scrollable pre instead of floating over its code. */
  display: flex;
  flex-direction: column;
  margin: 0 0 0.6rem;
}
.markdown :deep(.code-lang) {
  position: absolute;
  top: 0.25rem;
  right: 0.5rem;
  font-size: 0.75rem;
  color: var(--muted);
}
.markdown :deep(.code-copy) {
  /* CW-UX-23: static flow member of the block, pinned to its end edge —
     formerly absolute over the pre, where it occluded the last code lines. */
  position: static;
  align-self: flex-end;
  margin: 0.3rem 0 0;
  padding: 0.05rem 0.45rem;
  font-size: 0.75rem;
  color: var(--muted);
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 4px;
}
.markdown :deep(pre) {
  margin: 0;
  padding: 0.75rem;
  overflow-x: auto;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 6px;
}
.markdown :deep(pre code) {
  background: none;
  padding: 0;
}
.markdown :deep(.hl-comment) {
  color: var(--muted);
  font-style: italic;
}
.markdown :deep(.hl-string) {
  color: var(--ok);
}
.markdown :deep(.hl-number),
.markdown :deep(.hl-literal) {
  color: var(--warn);
}
.markdown :deep(.hl-keyword),
.markdown :deep(.hl-tag) {
  color: var(--accent);
}
.markdown :deep(.hl-variable),
.markdown :deep(.hl-attr),
.markdown :deep(.hl-key) {
  color: var(--error);
}
.markdown :deep(.hl-meta) {
  color: var(--muted);
}
.markdown :deep(.hl-add) {
  background: var(--diff-add);
}
.markdown :deep(.hl-del) {
  background: var(--diff-del);
}
.markdown :deep(a) {
  color: var(--accent);
}
.markdown :deep(table) {
  border-collapse: collapse;
  margin: 0 0 0.6rem;
}
.markdown :deep(th),
.markdown :deep(td) {
  border: 1px solid var(--line);
  padding: 0.25rem 0.5rem;
}
</style>
