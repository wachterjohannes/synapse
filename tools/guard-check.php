<?php
// Calls every tool the server lists with a reader token and checks who is turned away.
// usage: php tools/guard-check.php MCP_URL READER_TOKEN NOTES_DIR
// A tool that is not marked read-only has to refuse the reader. A read-only tool must not.
// The tools come from tools/list, so a tool added later is checked without touching this file.
[, $url, $token, $notes] = $argv + [null, null, null, null];

$post = static function (array $payload, ?string $session = null) use ($url, $token): array {
    $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer '.$token];
    if (null !== $session) {
        $headers[] = 'Mcp-Session-Id: '.$session;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
    $raw = (string) curl_exec($curl);
    $head = substr($raw, 0, curl_getinfo($curl, CURLINFO_HEADER_SIZE));
    $body = substr($raw, strlen($head));
    preg_match('/^mcp-session-id: (\S+)/mi', $head, $m);
    $json = preg_match('/^data: (.+)$/m', $body, $d) ? $d[1] : $body;

    return [json_decode($json, true) ?? [], $m[1] ?? $session];
};
$state = static fn () => is_dir($notes) ? md5(implode("\n", array_map(static fn ($f) => $f.md5_file($f), (static function () use ($notes) {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($notes, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = (string) $file;
    }
    sort($files);

    return $files;
})()))) : '';

[, $session] = $post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'guard-check', 'version' => '1']]]);
$post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);
[$list] = $post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $session);
$tools = $list['result']['tools'] ?? [];
if ([] === $tools) {
    fwrite(STDERR, "tools/list returned no tools\n");
    exit(1);
}

$dummy = static fn (array $schema) => match ($schema['type'] ?? 'string') {
    'array' => [], 'integer', 'number' => 1, 'boolean' => false, 'object' => new stdClass(), default => 'x',
};
$before = $state();
$failed = 0;
foreach ($tools as $i => $tool) {
    $args = [];
    foreach ($tool['inputSchema']['required'] ?? [] as $name) {
        $args[$name] = $dummy($tool['inputSchema']['properties'][$name] ?? []);
    }
    [$answer] = $post(['jsonrpc' => '2.0', 'id' => 10 + $i, 'method' => 'tools/call', 'params' => ['name' => $tool['name'], 'arguments' => $args ?: new stdClass()]], $session);
    $text = json_encode($answer);
    $refused = str_contains($text, 'may only read');
    $readOnly = true === ($tool['annotations']['readOnlyHint'] ?? false);
    $ok = $readOnly ? !$refused : ($refused && true === ($answer['result']['isError'] ?? false));
    printf("  %s  %-14s %s\n", $ok ? 'ok  ' : 'FAIL', $tool['name'], $readOnly ? 'read-only, open to a reader' : 'not read-only, refused for a reader');
    $failed += $ok ? 0 : 1;
}
if ($before !== $state()) {
    echo "  FAIL  the notes changed while a reader called the tools\n";
    ++$failed;
}
exit($failed ? 1 : 0);
