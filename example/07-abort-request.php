<?php
require(implode(DIRECTORY_SEPARATOR, ["..", "vendor", "autoload.php"]));

use GT\Fetch\AbortController;
use GT\Fetch\Http;

$http = new Http();
$controller = new AbortController();

$http->fetch("https://httpbin.org/delay/5", [
	"signal" => $controller->signal,
])
	->then(function() {
		echo "The request completed before it was aborted.", PHP_EOL;
	})
	->catch(function(Throwable $reason) {
		echo "Caught: ", $reason->getMessage(), PHP_EOL;
	});

echo "Aborting request...", PHP_EOL;
$controller->abort();
$http->wait();
echo "Done.", PHP_EOL;
