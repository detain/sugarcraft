/**
 * M-21 — the sidebar and transcript must not paint a confident emptiness
 * over an index that is still cold, or a transcript that is still in
 * flight. (1) Cold-start SessionSidebar: bare blank read as "no sessions"
 * — it now answers with a loading row instead. (2) A SessionView whose
 * subscribe has not landed painted the Transcript's "No transcript yet"
 * claim over the in-flight snapshot; (3) a failed subscribe painted the
 * SAME false claim right next to the honest session error. A warm, truly
 * empty list still says empty (positive controls).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { flush } from '../../__tests__/fakes';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createAppRouter } from '@/router';
import { createMemoryHistory } from 'vue-router';
import SessionSidebar from '@/components/SessionSidebar.vue';
import SessionView from '@/views/SessionView.vue';
import Transcript from '@/components/Transcript.vue';
import { useSessionStore } from '@/stores/session';
import { useSessionsStore } from '@/stores/sessions';
import { Wire } from '../grid/__tests__/harness';

let wire: Wire;

beforeEach(() => {
  setActivePinia(createPinia());
  wire = new Wire();
  try {
    globalThis.localStorage?.clear();
  } catch {
    // no storage
  }
});

describe('sidebar cold start is honest (M-21)', () => {
  it('paints a loading row before the first sessions list lands, not a blank list', async () => {
    // The cold shape is the store's own default: nothing has loaded and
    // nothing failed. Mounting paints exactly that window.
    const sessions = useSessionsStore();
    expect(sessions.loaded).toBe(false);
    expect(sessions.error).toBe(null);

    const router = createAppRouter(createMemoryHistory());
    await router.push({ name: 'session', params: { id: 'x' } });
    await router.isReady();
    const wrapper = mount(SessionSidebar, {
      props: { activeId: 'x' },
      global: { plugins: [router] },
    });

    const loading = wrapper.find('[data-testid="sidebar-loading"]');
    expect(loading.exists()).toBe(true);
    expect(loading.text()).toContain('Loading sessions');
    // The false claim is gone: nothing on this screen says "No sessions".
    expect(wrapper.text()).not.toContain('No sessions yet');

    // Warm AND empty — the honest claim returns to the same position.
    // load() pages `session.list` once (dirBrowse absent in the harness
    // hello, showArchived false) — answer it so the walk can settle.
    wire = await Wire.connect();
    const pending = sessions.load();
    wire.answer('session.list', { items: [], nextCursor: null });
    await flush();
    await pending;
    await wrapper.vm.$nextTick();
    expect(sessions.loaded).toBe(true);
    expect(wrapper.find('[data-testid="sidebar-loading"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('No sessions yet');
  });
});

describe('transcript during subscribe is honest (M-21)', () => {
  it('paints a loading row while the subscribe is in flight, not "No transcript yet"', async () => {
    wire = await Wire.connect();
    const session = useSessionStore('s1');
    // Keep the request in flight: never answered, so phase stays
    // 'subscribing' — exactly the window the false claim used to paint.
    const subscribed = session.subscribe();
    expect(session.phase).toBe('subscribing');

    const wrapper = mount(SessionView, { props: { id: 's1' }, global: { plugins: [] } });

    const loading = wrapper.find('[data-testid="transcript-loading"]');
    expect(loading.exists()).toBe(true);
    expect(loading.text()).toContain('Loading the transcript');
    expect(wrapper.find('[data-testid="transcript-empty"]').exists()).toBe(false);

    // Warm the session (the same answered subscribe the other views use).
    wire.answer('session.subscribe', {
      reset: true,
      throughSeq: 1,
      pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 1, pendingAsks: [], queue: [], messages: [] },
    });
    await subscribed;
    await flushPromises();

    // A live session with no messages still says the honest empty.
    expect(wrapper.find('[data-testid="transcript-loading"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="transcript-empty"]').exists()).toBe(true);
  });

  it('a failed subscribe paints the error WITHOUT the false "No transcript yet" claim', async () => {
    wire = await Wire.connect();
    const session = useSessionStore('s1');
    // Mount FIRST: SessionView's retain() starts the subscribe, and the
    // failure must land while the view is alive (mounting after a failure
    // would auto-re-subscribe on a fresh in-flight frame instead).
    const wrapper = mount(SessionView, { props: { id: 's1' }, global: { plugins: [] } });
    expect(session.phase).toBe('subscribing');
    const frame = wire.sent('session.subscribe').at(-1);
    if (!frame) throw new Error('no subscribe frame');
    wire.socket.fail(-32000, 'server_error', 'no room', frame.id);
    await flushPromises();
    expect(session.error).toContain('no room');

    // The error is visible (existing row) and the lying empty row is gone.
    expect(wrapper.text()).toContain('no room');
    expect(wrapper.find('[data-testid="transcript-empty"]').exists()).toBe(false);
  });

  it('a direct consumer that passes neither flag still gets the honest empty (positive control)', () => {
    const wrapper = mount(Transcript, { props: { items: [] } });
    expect(wrapper.find('[data-testid="transcript-loading"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="transcript-empty"]').exists()).toBe(true);
  });
});
