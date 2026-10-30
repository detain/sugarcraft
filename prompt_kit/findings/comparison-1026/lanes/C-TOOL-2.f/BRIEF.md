### C-TOOL-2.f — MCP images/PDF resources into the tool-result channel
- item: C-TOOL-2 (sources: GAP-MCP-14, k2-sum MCP-14)
- kind: gap · effort S
- approach: `McpToolBridge::replyResult()` (`candy-code/src/Tools/McpToolBridge.php:600`) renders image/audio parts as size text (`renderInlineBytes` :839). Change: decode the FIRST in-cap image part whose bytes sniff (`FileMentions::sniffImage`) into the `ToolResult` image channel, keeping the size line for every part so non-vision wires lose nothing. An embedded `application/pdf` resource blob goes to `documentBytes` (from C-TOOL-2.d). Audio stays described. `McpCallTool` shares `replyResult`, so it inherits this with no change (verify).
- files.create: `candy-code/tests/Tools/McpToolBridgeImageChannelTest.php`
- files.modify: `candy-code/src/Tools/McpToolBridge.php` (replyResult, renderInlineBytes), `candy-code/docs/MCP.md` (output section)
- tests: create the file above (first image wins; an oversize image stays text; a PDF resource goes to the document channel; audio unchanged); modify `candy-code/tests/Tools/McpToolBridgeTest.php`, `candy-code/tests/Tools/McpToolBridgeResultCapTest.php`
- docs/guards: `docs/MCP.md` in-step; `DocFigureProseDriftTest` MCP arms if a counted sentence moves
- serialized: [docs:MCP.md]
- deps: C-TOOL-2.d
- phase: normal
- ruling: none
- risks: a server returning many screenshots per call sends only the first; state it in the caption.

