<?php
declare(strict_types=1);

ini_set("memory_limit", "1G");

const DEFAULT_ADDRESS = "127.0.0.1";
const DEFAULT_PORT = 8080;
const SELECT_TIMEOUT_USEC = 20000;
const MAX_REQUEST_BYTES = 16384;

if(in_array("--help", $argv, true) || in_array("-h", $argv, true)) {
	echo <<<TEXT
Standalone concurrent stress-test target for PhpGt/Fetch.

Usage:
  php example/target/06-stress-test-server.php [address] [port]

Defaults:
  address = %s
  port    = %d

Examples:
  php example/target/06-stress-test-server.php
  php example/06-stress-test.php 'http://127.0.0.1:8080/?delay-ms=100'
  php example/06-stress-test.php 'http://127.0.0.1:8080/?type=big-page&size-kb=256&delay-ms=25'

Query parameters:
  type=hello|big-page|json|status
  delay-ms=0..60000
  min-delay-ms=0..60000
  max-delay-ms=0..60000
  speed-kbps=1..102400       Limits response body throughput
  size-kb=1..1024            Used by type=big-page
  status-code=100..599       Used by type=status
  body=...                   Used by type=status

Defaults:
  type=hello
  delay-ms=random(10..250)
  hello body="Hello, World!"
  big-page size=random(1..1024 KB), content is random letters and digits

Press Ctrl+C to stop the server.
TEXT, DEFAULT_ADDRESS, DEFAULT_PORT;
	exit(0);
}

$address = $argv[1] ?? DEFAULT_ADDRESS;
$port = (int)($argv[2] ?? DEFAULT_PORT);

$server = stream_socket_server(
	sprintf("tcp://%s:%d", $address, $port),
	$errorCode,
	$errorMessage
);

if($server === false) {
	fwrite(STDERR, "Failed to start server: $errorMessage ($errorCode)\n");
	exit(1);
}

stream_set_blocking($server, false);

/** @var array<int, array{socket: resource, readBuffer: string, headerBuffer: string, bodyBuffer: string, readyAt: float|null, remoteAddress: string, requestLine: string|null, bodyBytesPerSecond: int, bodyStartedAt: float|null, bodyBytesSent: int}> $clients */
$clients = [];
$completed = 0;
$startedAt = microtime(true);

printf("Listening on http://%s:%d\n", $address, $port);
echo "Example: http://{$address}:{$port}/?type=big-page&size-kb=256&delay-ms=50", PHP_EOL;

while(true) {
	$readSockets = [$server];
	$writeSockets = [];

	foreach($clients as $client) {
		$readSockets[] = $client["socket"];

		if(($client["headerBuffer"] !== "" || $client["bodyBuffer"] !== "")
		&& $client["readyAt"] !== null
		&& $client["readyAt"] <= microtime(true)) {
			$writeSockets[] = $client["socket"];
		}
	}

	$exceptSockets = null;
	$selectedCount = @stream_select(
		$readSockets,
		$writeSockets,
		$exceptSockets,
		0,
		SELECT_TIMEOUT_USEC
	);

	if($selectedCount === false) {
		continue;
	}

	foreach($readSockets as $socket) {
		if($socket === $server) {
			acceptClients($server, $clients);
			continue;
		}

		readClient($socket, $clients);
	}

	foreach($writeSockets as $socket) {
		writeClient($socket, $clients, $completed, $startedAt);
	}
}

/**
 * @param resource $server
 * @param array<int, array{socket: resource, readBuffer: string, headerBuffer: string, bodyBuffer: string, readyAt: float|null, remoteAddress: string, requestLine: string|null, bodyBytesPerSecond: int, bodyStartedAt: float|null, bodyBytesSent: int}> $clients
 */
function acceptClients($server, array &$clients):void {
	while($clientSocket = @stream_socket_accept($server, 0, $remoteAddress)) {
		stream_set_blocking($clientSocket, false);
		$id = (int)$clientSocket;
		$clients[$id] = [
			"socket" => $clientSocket,
			"readBuffer" => "",
			"headerBuffer" => "",
			"bodyBuffer" => "",
			"readyAt" => null,
			"remoteAddress" => $remoteAddress ?: "unknown",
			"requestLine" => null,
			"bodyBytesPerSecond" => 0,
			"bodyStartedAt" => null,
			"bodyBytesSent" => 0,
		];
	}
}

/**
 * @param resource $socket
 * @param array<int, array{socket: resource, readBuffer: string, headerBuffer: string, bodyBuffer: string, readyAt: float|null, remoteAddress: string, requestLine: string|null, bodyBytesPerSecond: int, bodyStartedAt: float|null, bodyBytesSent: int}> $clients
 */
function readClient($socket, array &$clients):void {
	$id = (int)$socket;
	if(!isset($clients[$id])) {
		return;
	}

	$chunk = fread($socket, 8192);
	if($chunk === "" || $chunk === false) {
		if(feof($socket)) {
			closeClient($id, $clients);
		}
		return;
	}

	$clients[$id]["readBuffer"] .= $chunk;

	if(strlen($clients[$id]["readBuffer"]) > MAX_REQUEST_BYTES) {
		$response = buildHttpResponse(
			413,
			"text/plain; charset=utf-8",
			"Request too large\n"
		);
		$clients[$id]["headerBuffer"] = $response["header"];
		$clients[$id]["bodyBuffer"] = $response["body"];
		$clients[$id]["readyAt"] = microtime(true);
		return;
	}

	if(!str_contains($clients[$id]["readBuffer"], "\r\n\r\n")) {
		return;
	}

	[$head] = explode("\r\n\r\n", $clients[$id]["readBuffer"], 2);
	$requestLine = explode("\r\n", $head)[0] ?? "GET / HTTP/1.1";
	$clients[$id]["requestLine"] = $requestLine;

	$requestParts = explode(" ", $requestLine);
	$target = $requestParts[1] ?? "/";
	$response = buildResponseFromTarget($target);
	$httpResponse = buildHttpResponse(
		$response["statusCode"],
		$response["contentType"],
		$response["body"]
	);

	$clients[$id]["headerBuffer"] = $httpResponse["header"];
	$clients[$id]["bodyBuffer"] = $httpResponse["body"];
	$clients[$id]["readyAt"] = microtime(true) + ($response["delayMs"] / 1000);
	$clients[$id]["bodyBytesPerSecond"] = $response["bodyBytesPerSecond"];
	$clients[$id]["bodyStartedAt"] = null;
	$clients[$id]["bodyBytesSent"] = 0;
}

/**
 * @param resource $socket
 * @param array<int, array{socket: resource, readBuffer: string, headerBuffer: string, bodyBuffer: string, readyAt: float|null, remoteAddress: string, requestLine: string|null, bodyBytesPerSecond: int, bodyStartedAt: float|null, bodyBytesSent: int}> $clients
 */
function writeClient(
	$socket,
	array &$clients,
	int &$completed,
	float $startedAt
):void {
	$id = (int)$socket;
	if(!isset($clients[$id])) {
		return;
	}

	if($clients[$id]["headerBuffer"] !== "") {
		$written = fwrite($socket, $clients[$id]["headerBuffer"]);
		if($written === false) {
			closeClient($id, $clients);
			return;
		}

		$clients[$id]["headerBuffer"] = substr($clients[$id]["headerBuffer"], $written);
		if($clients[$id]["headerBuffer"] !== "") {
			return;
		}

		$clients[$id]["bodyStartedAt"] = microtime(true);
	}

	if($clients[$id]["bodyBuffer"] !== "") {
		$bodyChunk = nextBodyChunk($clients[$id]);
		if($bodyChunk === "") {
			return;
		}

		$written = fwrite($socket, $bodyChunk);
		if($written === false) {
			closeClient($id, $clients);
			return;
		}

		$clients[$id]["bodyBuffer"] = substr($clients[$id]["bodyBuffer"], $written);
		$clients[$id]["bodyBytesSent"] += $written;
	}

	if($clients[$id]["headerBuffer"] !== "" || $clients[$id]["bodyBuffer"] !== "") {
		return;
	}

	$completed++;
	printf(
		"[%0.2fs] completed=%d remote=%s request=\"%s\"\n",
		microtime(true) - $startedAt,
		$completed,
		$clients[$id]["remoteAddress"],
		$clients[$id]["requestLine"] ?? "unknown"
	);

	closeClient($id, $clients);
}

/**
 * @return array{statusCode: int, contentType: string, body: string, delayMs: int, bodyBytesPerSecond: int}
 */
function buildResponseFromTarget(string $target):array {
	$parts = parse_url($target);
	parse_str($parts["query"] ?? "", $query);

	$type = strtolower((string)($query["type"] ?? "hello"));
	$delayMs = resolveDelayMilliseconds($query);
	$bodyBytesPerSecond = resolveBodyBytesPerSecond($query);

	return match($type) {
		"big-page" => [
			"statusCode" => 200,
			"contentType" => "text/html; charset=utf-8",
			"body" => buildBigPageBody($query),
			"delayMs" => $delayMs,
			"bodyBytesPerSecond" => $bodyBytesPerSecond,
		],
		"json" => [
			"statusCode" => 200,
			"contentType" => "application/json",
			"body" => json_encode([
				"ok" => true,
				"type" => $type,
				"delayMs" => $delayMs,
				"query" => $query,
				"timestamp" => microtime(true),
			], JSON_THROW_ON_ERROR),
			"delayMs" => $delayMs,
			"bodyBytesPerSecond" => $bodyBytesPerSecond,
		],
		"status" => [
			"statusCode" => clampInt((int)($query["status-code"] ?? 503), 100, 599),
			"contentType" => "text/plain; charset=utf-8",
			"body" => (string)($query["body"] ?? "Configured status response\n"),
			"delayMs" => $delayMs,
			"bodyBytesPerSecond" => $bodyBytesPerSecond,
		],
		default => [
			"statusCode" => 200,
			"contentType" => "text/plain; charset=utf-8",
			"body" => "Hello, World!\n",
			"delayMs" => $delayMs,
			"bodyBytesPerSecond" => $bodyBytesPerSecond,
		],
	};
}

/**
 * @param array<string, mixed> $query
 */
function buildBigPageBody(array $query):string {
	$sizeKb = isset($query["size-kb"])
		? clampInt((int)$query["size-kb"], 1, 1024)
		: random_int(1, 1024);
	$totalBytes = $sizeKb * 1024;

	$title = sprintf("Stress test payload: %d KB", $sizeKb);
	$prefix = "<!doctype html>\n<html><head><title>{$title}</title></head><body><pre>";
	$suffix = "</pre></body></html>\n";
	$contentBytes = max(0, $totalBytes - strlen($prefix) - strlen($suffix));

	return $prefix . randomAlphaNumeric($contentBytes) . $suffix;
}

/**
 * @param array<string, mixed> $query
 */
function resolveDelayMilliseconds(array $query):int {
	if(isset($query["delay-ms"])) {
		return clampInt((int)$query["delay-ms"], 0, 60000);
	}

	$minDelay = clampInt((int)($query["min-delay-ms"] ?? 10), 0, 60000);
	$maxDelay = clampInt((int)($query["max-delay-ms"] ?? 250), $minDelay, 60000);
	return random_int($minDelay, $maxDelay);
}

/**
 * @param array<string, mixed> $query
 */
function resolveBodyBytesPerSecond(array $query):int {
	if(!isset($query["speed-kbps"])) {
		return 0;
	}

	return clampInt((int)$query["speed-kbps"], 1, 102400) * 1024;
}

/**
 * @param array{socket: resource, readBuffer: string, headerBuffer: string, bodyBuffer: string, readyAt: float|null, remoteAddress: string, requestLine: string|null, bodyBytesPerSecond: int, bodyStartedAt: float|null, bodyBytesSent: int} $client
 */
function nextBodyChunk(array $client):string {
	if($client["bodyBytesPerSecond"] <= 0) {
		return $client["bodyBuffer"];
	}

	if($client["bodyStartedAt"] === null) {
		return "";
	}

	$elapsedSeconds = max(0, microtime(true) - $client["bodyStartedAt"]);
	$allowedBytes = (int)floor($elapsedSeconds * $client["bodyBytesPerSecond"]);
	$availableBytes = $allowedBytes - $client["bodyBytesSent"];

	if($availableBytes <= 0) {
		return "";
	}

	return substr($client["bodyBuffer"], 0, $availableBytes);
}

/**
 * @return array{header: string, body: string}
 */
function buildHttpResponse(int $statusCode, string $contentType, string $body):array {
	$statusText = statusText($statusCode);
	$header = implode("\r\n", [
		"HTTP/1.1 {$statusCode} {$statusText}",
		"Content-Type: {$contentType}",
		"Content-Length: " . strlen($body),
		"Connection: close",
		"Cache-Control: no-store",
		"",
	]);

	return [
		"header" => $header . "\r\n",
		"body" => $body,
	];
}

function statusText(int $statusCode):string {
	return match($statusCode) {
		200 => "OK",
		201 => "Created",
		204 => "No Content",
		400 => "Bad Request",
		404 => "Not Found",
		413 => "Payload Too Large",
		429 => "Too Many Requests",
		500 => "Internal Server Error",
		502 => "Bad Gateway",
		503 => "Service Unavailable",
		default => "Response",
	};
}

function randomAlphaNumeric(int $length):string {
	if($length <= 0) {
		return "";
	}

	$alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
	$maxIndex = strlen($alphabet) - 1;
	$output = "";

	for($i = 0; $i < $length; $i++) {
		$output .= $alphabet[random_int(0, $maxIndex)];
	}

	return $output;
}

function clampInt(int $value, int $min, int $max):int {
	if($value < $min) {
		return $min;
	}

	if($value > $max) {
		return $max;
	}

	return $value;
}

/**
 * @param array<int, array{socket: resource, readBuffer: string, headerBuffer: string, bodyBuffer: string, readyAt: float|null, remoteAddress: string, requestLine: string|null, bodyBytesPerSecond: int, bodyStartedAt: float|null, bodyBytesSent: int}> $clients
 */
function closeClient(int $id, array &$clients):void {
	if(!isset($clients[$id])) {
		return;
	}

	fclose($clients[$id]["socket"]);
	unset($clients[$id]);
}
