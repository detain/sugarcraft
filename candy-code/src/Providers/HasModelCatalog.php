<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * PRV-1 (§6.5): a provider whose endpoint can answer "which models do you
 * have?" without guessing. Implemented by the OpenAI-compatible transports
 * (`openai`, `custom`, `sglang`), which all speak `GET {root}/v1/models`;
 * Anthropic, Bedrock, Vertex and the offline providers have no such route
 * and their catalogs come from {@see ModelMetadata::candidates()} instead.
 *
 * The contract is deliberately network-honest: {@see listModels()} never
 * throws and null means "unknown" (unreachable host, refused route, junk
 * body), which callers render as the absence of a live tier — never as a
 * failure. {@see \SugarCraft\Code\Providers\ModelCatalog} holds the
 * once-per-process memo so repeated palette opens stay render-path safe.
 *
 * The async sibling C-PRV-5.c added for the non-blocking palette prime is
 * deliberately NOT on this contract: `listModelsAsync(?callable $sender)`
 * lives on the two concrete OpenAI-compatible transports whose catalog
 * reads run on the event loop (`OpenAIProvider`, `SglangProvider`), because
 * widening the interface would break every other implementer — the custom
 * gateway and the suite's anonymous test doubles answer the sync contract
 * only. {@see \SugarCraft\Code\Providers\ModelCatalog::prefetchAsync()} is
 * the sanctioned dispatcher over that pair and answers null — "nothing
 * async started, stay on the sync prime" — for everything else, so callers
 * never need to know which transports qualify.
 *
 * This is a SugarCraft architecture interface, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
interface HasModelCatalog
{
    /**
     * The identity of the catalog fetch — normally the server root the list
     * endpoint hangs off. Two providers with the same key share one fetch.
     */
    public function catalogKey(): ?string;

    /**
     * The model ids the endpoint reports, in its own order; null when the
     * server cannot be asked or answers with junk. May block on one bounded
     * metadata GET — call from update-side code, never from a render path
     * ({@see ModelCatalog::prefetch()} is the sanctioned caller).
     *
     * @return list<string>|null
     */
    public function listModels(): ?array;
}
