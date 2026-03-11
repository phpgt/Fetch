<?php
declare(strict_types=1);

require implode(DIRECTORY_SEPARATOR, [__DIR__, "..", "vendor", "autoload.php"]);

use Gt\Fetch\Http;
use Gt\Http\Response;

const DEFAULT_URL = "http://127.0.0.1:8080/?type=big-page&size-kb=512&delay-ms=25&speed-kbps=2048";
const DEFAULT_START = 100;
const DEFAULT_STEP = 100;
const DEFAULT_MAX = 1000;

if(in_array("--help", $argv, true) || in_array("-h", $argv, true)) {
	printf(<<<TEXT
Stress test example for PhpGt/Fetch.

Usage:
  php example/06-stress-test.php [url] [start] [step] [max]

Defaults:
  url   = %s
  start = %d
  step  = %d
  max   = %d

Quick local smoke test:
  php example/target/06-stress-test-server.php
  php example/06-stress-test.php

The script increases the number of simultaneous fetches each round and
reports timing plus success/failure counts. The first failing round is a
practical concurrency ceiling for the current environment.

The bundled target server accepts concurrent connections in a single
non-blocking process and can simulate variable latency and response sizes.
You can still point this script at another controlled endpoint if needed.
TEXT, DEFAULT_URL, DEFAULT_START, DEFAULT_STEP, DEFAULT_MAX);
	exit(0);
}

$url = $argv[1] ?? DEFAULT_URL;
$start = max(1, (int)($argv[2] ?? DEFAULT_START));
$step = max(1, (int)($argv[3] ?? DEFAULT_STEP));
$max = max($start, (int)($argv[4] ?? DEFAULT_MAX));

printf("Target: %s\n", $url);
printf(
	"Ramping concurrency from %d to %d in steps of %d\n\n",
	$start,
	$max,
	$step
);

$firstFailureAt = null;

for($concurrency = $start; $concurrency <= $max; $concurrency += $step) {
	$http = new Http();
	$successCount = 0;
	$errorCount = 0;
	$statusCounts = [];
	$errorMessages = [];

	$startedAt = microtime(true);

	for($i = 0; $i < $concurrency; $i++) {
		$requestUrl = sprintf(
			"%s%srequest-id=%d-%d",
			$url,
			str_contains($url, "?") ? "&" : "?",
			$concurrency,
			$i
		);

		$http->fetch($requestUrl)
			->then(function(Response $response) use(
				&$successCount,
				&$errorCount,
				&$statusCounts
			) {
				$statusCode = $response->status;
				$statusCounts[$statusCode] ??= 0;
				$statusCounts[$statusCode]++;

				if($response->ok) {
					$successCount++;
					return;
				}

				$errorCount++;
			})
			->catch(function(Throwable $throwable) use(
				&$errorCount,
				&$errorMessages
			) {
				$errorCount++;
				$errorMessages[$throwable->getMessage()] ??= 0;
				$errorMessages[$throwable->getMessage()]++;
			});
	}

	try {
		$http->wait();
	}
	catch(Throwable $throwable) {
		$errorCount++;
		$errorMessages[$throwable->getMessage()] ??= 0;
		$errorMessages[$throwable->getMessage()]++;
	}

	$durationSeconds = microtime(true) - $startedAt;
	$requestsPerSecond = $durationSeconds > 0
		? $concurrency / $durationSeconds
		: 0;

	printf(
		"Concurrency %4d | ok %4d | errors %4d | %.3fs | %.2f req/s\n",
		$concurrency,
		$successCount,
		$errorCount,
		$durationSeconds,
		$requestsPerSecond
	);

	if($statusCounts) {
		ksort($statusCounts);
		echo "  Statuses: ";
		foreach($statusCounts as $statusCode => $count) {
			printf("%d=%d ", $statusCode, $count);
		}
		echo PHP_EOL;
	}

	if($errorMessages) {
		echo "  Errors: ";
		foreach($errorMessages as $message => $count) {
			printf("[%dx] %s ", $count, $message);
		}
		echo PHP_EOL;
	}

	if($firstFailureAt === null && $errorCount > 0) {
		$firstFailureAt = $concurrency;
	}
}

echo PHP_EOL;

if($firstFailureAt !== null) {
	printf(
		"First failing round: %d concurrent requests\n",
		$firstFailureAt
	);
	exit(1);
}

printf(
	"No failures observed up to %d concurrent requests\n",
	$max
);
