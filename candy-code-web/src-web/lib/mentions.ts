// B-24 (cc-fix-1111 lane m36): the Composer mention/search machinery, moved
// verbatim out of components/Composer.vue (:140-279 + :284-315 of the
// abd4fc06d tree) so the @file/@mcp/@agent popup logic no longer lives in the
// component module. Moved bodies keep the component's punctuation verbatim
// (mechanical-move ruling); the only substitutions are the documented
// props.* -> option-ref rebinding listed in the lane report.

import { computed, onBeforeUnmount, ref, watch } from "vue";

/**
 * C-MCP-2.c: one row of the `@mcp:` popup. Accepting inserts the keyword
 * form `@mcp:<server>:<uri>` the server already resolves; the roster fetch
 * and its cache live in `SessionView`, which owns the connection.
 */
export type McpMention = { server: string; uri: string; name: string };

export interface UseMentionSearchOptions {
    modelValue: { readonly value: string };
    slashCount: { readonly value: number };
    searchFiles: { readonly value: ((query: string) => Promise<string[]>) | undefined };
    searchMcpResources: { readonly value: ((query: string) => Promise<McpMention[]>) | undefined };
    agentRoster: { readonly value: (() => string[]) | undefined };
    emit: (event: "update:modelValue", value: string) => void;
    focusInput: () => void;
}

/**
 * Owns the three mention candidate lists, the debounced search dispatch, and
 * the accept/complete helpers. Registered from Composer.vue's setup scope, so
 * the unmount cleanup binds to the component exactly as it did when the code
 * lived inline.
 */
export function useMentionSearch(options: UseMentionSearchOptions) {
    const {
        modelValue,
        slashCount,
        searchFiles,
        searchMcpResources,
        agentRoster,
        emit,
        focusInput,
    } = options;

    /** Ranked `fs.search` matches for the trailing `@partial`, capped to one popup. */
    const files = ref<string[]>([]);
    /** Ranked `mcp.resources` rows for a trailing `@mcp:` partial (C-MCP-2.c). */
    const mcpMentions = ref<McpMention[]>([]);
    const agentMentions = ref<string[]>([]);
    let fileSeq = 0;

    /** C-MCP-2.c: an `@mcp:` partial opens the resource popup, not the file one. */
    const MCP_MENTION_PREFIX = "mcp:";

    /** C-AGT-4.d: an `@agent:` partial opens the roster popup, the same trick. */
    const AGENT_MENTION_PREFIX = "agent:";

    /**
     * The `@` completion rides the same keyboard as `/`: a trailing `@partial`
     * opens the file popup — unless the slash popup already owns the line.
     */
    const mentionPartial = computed(() => {
        if (slashCount.value > 0) return null;
        const match = /(?:^|\s)@([^\s@]*)$/.exec(modelValue.value);
        return match ? (match[1] ?? "") : null;
    });

    /**
     * WEB-5-P3 (client half): the `@` popup used to fire a synchronous `fs.search`
     * on the shared serve loop for every keystroke after `@`. This is a leading +
     * trailing debounce: a partial arriving more than MENTION_SEARCH_DEBOUNCE_MS
     * after the last search runs at once — the first `@` still opens instantly —
     * while partials inside the window collapse to one trailing search carrying
     * the newest partial. A null partial cancels the pending timer and resets the
     * window. The `fileSeq` latch (a late answer never overwrites a newer one)
     * stays; the timer is cleared on unmount.
     */
    const MENTION_SEARCH_DEBOUNCE_MS = 120;
    let mentionTimer: ReturnType<typeof setTimeout> | undefined;
    let lastSearchAt = Number.NEGATIVE_INFINITY;

    function searchMentionedFiles(partial: string): void {
        const seq = ++fileSeq;
        mcpMentions.value = []; // the file popup has taken the line back
        agentMentions.value = [];
        lastSearchAt = Date.now();
        void searchFiles
            .value!(partial)
            .then((found) => {
                if (seq === fileSeq) files.value = found.slice(0, 8);
            })
            .catch(() => {
                if (seq === fileSeq) files.value = [];
            });
    }

    /**
     * C-MCP-2.c: the `@mcp:` popup. Rows come from the session-cached
     * `mcp.resources` roster, filtered by the caller. No rows — `not-started`,
     * no servers, a failed fetch — hands the line to the file search the
     * partial would have run before this feature existed.
     */
    function searchMcpMentions(partial: string): void {
        const seq = ++fileSeq;
        files.value = [];
        lastSearchAt = Date.now();
        void searchMcpResources
            .value!(partial.slice(MCP_MENTION_PREFIX.length))
            .then((rows) => {
                if (seq !== fileSeq) return;
                mcpMentions.value = rows.slice(0, 8);
                if (mcpMentions.value.length === 0 && searchFiles.value)
                    searchMentionedFiles(partial);
            })
            .catch(() => {
                if (seq !== fileSeq) return;
                mcpMentions.value = [];
                if (searchFiles.value) searchMentionedFiles(partial);
            });
    }

    /**
     * C-AGT-4.d (client-only): the `@agent:` popup. The names are the agents
     * store's launch roster the StatusBar already fetched — no second wire call
     * per keystroke — filtered and sorted right here. Empty rows — before the
     * catalogue lands, or a roster with no match — hand the line back to the
     * file search, exactly as the `@mcp:` popup does.
     */
    function searchAgentMentions(partial: string): void {
        ++fileSeq; // no async leg to guard here, but the bump discards any in-flight search's continuation
        files.value = [];
        mcpMentions.value = [];
        lastSearchAt = Date.now();
        const query = partial.slice(AGENT_MENTION_PREFIX.length).toLowerCase();
        const names = (agentRoster.value?.() ?? [])
            .filter((name) => name.toLowerCase().includes(query))
            .sort()
            .slice(0, 8);
        agentMentions.value = names;
        if (names.length === 0 && searchFiles.value) searchMentionedFiles(partial);
    }

    /** Route a partial to an `@agent:`/`@mcp:` roster popup or the `fs.search` file popup. */
    function runMentionSearch(partial: string): void {
        if (partial.startsWith(AGENT_MENTION_PREFIX) && agentRoster.value)
            searchAgentMentions(partial);
        else if (partial.startsWith(MCP_MENTION_PREFIX) && searchMcpResources.value)
            searchMcpMentions(partial);
        else if (searchFiles.value) searchMentionedFiles(partial);
    }

    watch(
        mentionPartial,
        (partial) => {
            if (mentionTimer !== undefined) {
                clearTimeout(mentionTimer);
                mentionTimer = undefined;
            }
            if (
                partial === null ||
                (!searchFiles.value &&
                    !searchMcpResources.value &&
                    !agentRoster.value)
            ) {
                files.value = [];
                mcpMentions.value = [];
                agentMentions.value = [];
                lastSearchAt = Number.NEGATIVE_INFINITY; // the next `@` opens at once
                return;
            }
            if (Date.now() - lastSearchAt >= MENTION_SEARCH_DEBOUNCE_MS) {
                runMentionSearch(partial);
                return;
            }
            mentionTimer = setTimeout(() => {
                mentionTimer = undefined;
                runMentionSearch(partial);
            }, MENTION_SEARCH_DEBOUNCE_MS);
        },
        { immediate: true },
    );
    onBeforeUnmount(() => {
        if (mentionTimer !== undefined) clearTimeout(mentionTimer);
    });

    function completeFile(path: string): void {
        const at = modelValue.value.lastIndexOf("@");
        if (at < 0) return;
        emit("update:modelValue", `${modelValue.value.slice(0, at)}@${path} `);
        focusInput();
    }

    /** C-MCP-2.c: accepting an `@mcp:` row inserts the server-resolvable keyword. */
    function completeMcp(mention: McpMention): void {
        const at = modelValue.value.lastIndexOf("@");
        if (at < 0) return;
        emit(
            "update:modelValue",
            `${modelValue.value.slice(0, at)}@mcp:${mention.server}:${mention.uri} `,
        );
        focusInput();
    }

    /**
     * C-AGT-4.d: accepting an agent row inserts the keyword the server's mention
     * scanner reserves — the same `@agent:<name>` text a TUI user completes, so
     * the one resolver decides the name in every front end.
     */
    function completeAgent(name: string): void {
        const at = modelValue.value.lastIndexOf("@");
        if (at < 0) return;
        emit(
            "update:modelValue",
            `${modelValue.value.slice(0, at)}@agent:${name} `,
        );
        focusInput();
    }

    return {
        files,
        mcpMentions,
        agentMentions,
        mentionPartial,
        completeFile,
        completeMcp,
        completeAgent,
    };
}
