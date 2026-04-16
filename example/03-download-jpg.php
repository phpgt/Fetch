<?php
require(implode(DIRECTORY_SEPARATOR, ["..", "vendor", "autoload.php"]));

use GT\Fetch\Http;
use GT\Http\Blob;
use GT\Http\Response;

/*
 * This example uses Cat-as-a-Service API to request a random photo of a cat.
 * See https://cataas.com/ for more information.
 */

// Example: Download an image from the API and store in the temp directory.

$http = new Http();
$http->fetch("https://cataas.com/cat")
->then(function(Response $response) {
	if(!$response->ok) {
		throw new RuntimeException("Error getting a cat. (ERROR $response->status)");
	}

	echo "Cat as a Service responded with a binary file with the following attributes:" . PHP_EOL;
	echo "Response size: " . $response->getHeaderLine("Content-Length") . PHP_EOL;
	echo "File type: " . $response->getHeaderLine("Content-Type") . PHP_EOL;
	return $response->blob();
})
->then(function(Blob $blob) {
	switch($blob->type) {
	case "image/jpeg":
		$extension = "jpg";
		break;

	case "image/png":
		$extension = "png";
		break;

	default:
		echo $blob->type . " type is not supported." . PHP_EOL;
		exit(1);
	}

	$file = new SplFileObject("/tmp/cat.$extension", "w");
	$bytesWritten = $file->fwrite($blob);
	echo "Written $bytesWritten bytes." . PHP_EOL;
	echo "Photo written to " . $file->getPathname() . PHP_EOL;
})
->catch(function(Throwable $reason) {
	echo "There was an error caught: ", $reason->getMessage(), PHP_EOL;
});

// To execute the above Promise(s), call wait() or all().
$http->wait();
