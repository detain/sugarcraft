<?php

declare(strict_types=1);

// Pagination fixture for the tools/list cursor walk (ISS-093(b)).
//   (default)        2 pages: 2 tools + nextCursor, then 1 tool, no cursor —
//                    the whole roster is only visible if the client follows
//   --repeat-cursor  every page re-issues the SAME nextCursor forever: the
//                    lying server the repeated-cursor refusal exists for
//   --endless        every page issues a FRESH cursor forever: the page
//                    ceiling, not the deadline, must be what refuses
//   --page2-error    page 1 is fine, the cursor follow-up answers -32601:
//                    a mid-walk failure is as fatal as a front one
// Each tools/list is logged to stderr with the cursor it carried, so a test
// can pin WHICH cursor was answered. Bounded: exits on stdin EOF or after
// 30s, so a broken test cannot leak it.

$repeat = in_array('--repeat-cursor', $argv, true);
$endless = in_array('--endless', $argv, true);
$page2Error = in_array('--page2-error', $argv, true);
$born = microtime(true);
$page = 0;

fwrite(STDERR, "paging-fixture: booted\n");

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

$tool = static fn (string $name): array => [
    'name' => $name,
    'description' => "the {$name} tool",
    'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
];

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) {
        continue;
    }

    if ($msg['method'] === 'initialize') {
        $write(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new stdClass(),
            'serverInfo' => ['name' => 'fixture-paging', 'version' => '0.0.1'],
        ]]);

        continue;
    }

    if ($msg['method'] === 'tools/list') {
        $page++;
        $cursor = $msg['params']['cursor'] ?? '(none)';
        fwrite(STDERR, "paging-fixture: tools/list page {$page} cursor {$cursor}\n");

        if ($page2Error && $page > 1) {
            $write(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'error' => [
                'code' => -32601,
                'message' => 'paging is not a service we offer',
            ]]);

            continue;
        }

        $result = $page === 1
            ? ['tools' => [$tool('alpha'), $tool('beta')]]
            : ['tools' => [$tool("tool_page_{$page}")]];

        if ($repeat) {
            $result['nextCursor'] = 'samecursor';
        } elseif ($endless) {
            $result['nextCursor'] = "fresh-{$page}";
        } elseif ($page === 1) {
            $result['nextCursor'] = 'second-page';
        }

        $write(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]);

        continue;
    }

    if (isset($msg['id'])) {
        $write(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => new stdClass()]);
    }
}
