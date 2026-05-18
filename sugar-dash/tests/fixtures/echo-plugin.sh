#!/usr/bin/env php
<?php
/**
 * Echo-plugin fixture — reads line-delimited JSON requests from stdin,
 * emits line-delimited JSON responses to stdout.
 *
 * Mirrors the lattice plugin protocol used by ExternalModule.
 *
 * Request types: init | update | view
 * Response types: init (on init) | update (on update) | view (on view)
 *
 * Example session:
 *   In:  {"type":"init","data":{}}
 *   Out: {"type":"init","data":{"name":"echo-plugin","minSize":[30,4],"interval":1}}
 *
 *   In:  {"type":"update","data":{"state":{"tick":1}}}
 *   Out: {"type":"update","data":{"state":{"tick":2}}}
 *
 *   In:  {"type":"view","data":{"width":80,"height":24,"state":{"tick":1}}}
 *   Out: {"type":"view","data":{"content":"tick: 1\n"}}
 */

declare(strict_types=1);

$tick = 0;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $request = json_decode($line, true);
    if (!is_array($request)) {
        fwrite(STDERR, "echo-plugin: invalid JSON: {$line}\n");
        continue;
    }

    $type = $request['type'] ?? 'unknown';
    $data = $request['data'] ?? [];

    switch ($type) {
        case 'init':
            $response = [
                'type' => 'init',
                'data' => [
                    // Omit name so ExternalModule uses its configured name
                    'minSize' => [30, 4],
                    'interval' => 1,
                ],
            ];
            break;

        case 'update':
            $state = $data['state'] ?? [];
            $tick = ($state['tick'] ?? $tick) + 1;
            $response = [
                'type' => 'update',
                'data' => [
                    'state' => ['tick' => $tick],
                ],
            ];
            break;

        case 'view':
            $width = $data['width'] ?? 80;
            $height = $data['height'] ?? 24;
            $state = $data['state'] ?? [];
            $tickVal = $state['tick'] ?? $tick;
            $content = "tick: {$tickVal}\n";
            $response = [
                'type' => 'view',
                'data' => [
                    'content' => $content,
                ],
            ];
            break;

        default:
            $response = [
                'type' => 'error',
                'data' => [
                    'message' => "unknown type: {$type}",
                ],
            ];
            break;
    }

    fwrite(STDOUT, json_encode($response) . "\n");
}
