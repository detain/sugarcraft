<script setup lang="ts">
import { ref } from 'vue'
import { AuthError, canvasLink } from '../protocol/auth'
import type { MediaRef } from '../protocol/generated'
import { t } from '../lib/i18n'

/**
 * One HTML artifact a finished `Canvas` tool produced (C-TOOL-6.g): the ref
 * rides the event with `kind: "html"`, and the page itself is fetched by the
 * browser — never by this client. On click the card mints a single-use link
 * (`POST /api/canvas`, docs/MEDIA.md "Canvas artifacts") and points an
 * iframe at it.
 *
 * The iframe is the containment door for model-authored markup. Its
 * `sandbox` attribute carries exactly `allow-scripts`: NO `allow-same-origin`
 * (the frame gets an opaque origin and can read no cookie, no storage, and
 * no same-origin API of the client), no `allow-popups`, no `allow-forms`, no
 * `allow-top-navigation`. `referrerpolicy="no-referrer"` keeps the token out
 * of any subresource the page's scripts conjure. The served document also
 * arrives under a server-side CSP whose `connect-src 'none'` pins the same
 * law from the other side — a canvas page that wants to phone home finds no
 * door open at either.
 *
 * Links are consumed on first sight and expire in 60 s, so the card mints a
 * fresh one on every show; there is nothing to revoke (no object URL exists)
 * and no reason to keep the frame's src after unmount (the URL is already
 * dead if it was ever loaded).
 */
const props = defineProps<{ media: MediaRef; sessionId?: string }>()

type Phase = 'idle' | 'loading' | 'ready' | 'error'

const phase = ref<Phase>('idle')
const problem = ref('')
const url = ref('')

async function show(): Promise<void> {
  if (props.sessionId === undefined || props.sessionId === '') {
    phase.value = 'error'
    problem.value = t('web.canvas.no_session')
    return
  }
  phase.value = 'loading'
  try {
    url.value = await canvasLink(props.sessionId, props.media.artifactId)
    phase.value = 'ready'
  } catch (error) {
    problem.value = error instanceof AuthError ? error.message : t('web.canvas.mint_failed')
    phase.value = 'error'
  }
}

/** Fold the frame away; the spent token makes a re-show the only way back. */
function hide(): void {
  url.value = ''
  phase.value = 'idle'
  problem.value = ''
}
</script>

<template>
  <figure class="canvas-card" :data-phase="phase" data-testid="canvas-card">
    <figcaption>
      <span class="badge" data-testid="canvas-badge">{{ t('web.canvas.badge') }}</span>
      <span class="artifact">{{ media.artifactId }}</span>
    </figcaption>
    <p v-if="phase === 'loading'" class="state" data-testid="canvas-loading">{{ t('web.canvas.linking') }}</p>
    <p v-else-if="phase === 'error'" class="state error" role="alert" data-testid="canvas-error">{{ problem }}</p>
    <button v-else-if="phase === 'idle'" type="button" class="show" data-testid="canvas-show" @click="void show()">{{ t('web.canvas.show') }}</button>
    <div v-else class="frame">
      <iframe
        sandbox="allow-scripts"
        referrerpolicy="no-referrer"
        :src="url"
        :title="t('web.canvas.frame_title')"
        class="page"
        data-testid="canvas-frame"
      />
      <button type="button" class="fold" data-testid="canvas-hide" @click="hide">{{ t('web.canvas.hide') }}</button>
    </div>
  </figure>
</template>

<style scoped>
.canvas-card {
  border: 1px solid var(--line);
  border-radius: var(--radius-3);
  margin: 0.5rem 0 0;
  padding: 0.5rem 0.75rem;
  background: var(--bg);
  overflow: hidden;
}
figcaption {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  font-size: 0.8rem;
  color: var(--muted);
}
.badge {
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-size: 0.65rem;
  border: 1px solid var(--line);
  border-radius: var(--radius-2);
  padding: 0 0.3rem;
}
.artifact {
  overflow-wrap: anywhere;
}
.state {
  margin: 0.5rem 0 0;
  color: var(--muted);
}
.state.error {
  color: var(--error);
}
button.show {
  margin: 0.5rem 0 0;
  font: inherit;
  color: var(--ink);
  background: none;
  border: 1px solid var(--line);
  border-radius: var(--radius-2);
  padding: 0.2rem 0.6rem;
  cursor: pointer;
}
.frame {
  margin: 0.5rem 0 0;
}
.page {
  width: 100%;
  height: 18rem;
  border: 1px solid var(--line);
  border-radius: var(--radius-2);
  /* CW-UX-01: theme-blind #fff literal, now a token. Invariant on purpose —
     canvas documents are model-authored HTML that assume white paper. */
  background: var(--canvas-paper);
}
.fold {
  margin: 0.35rem 0 0;
  font: inherit;
  font-size: 0.75rem;
  color: var(--muted);
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
}
</style>
