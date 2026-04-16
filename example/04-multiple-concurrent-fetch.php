<?php
require(implode(DIRECTORY_SEPARATOR, ["..", "vendor", "autoload.php"]));

use GT\Fetch\Http;
use GT\Http\Blob;
use GT\Http\Response;
use GT\Json\JsonKvpObject;
use GT\Json\JsonPrimitive\JsonArrayPrimitive;

$getJsonFromResponse = function(Response $response) {
	if(!$response->ok) {
		throw new RuntimeException("Can't retrieve Github's API on $response->uri");
	}

	return $response->json();
};
$listReposFromJson = function(JsonArrayPrimitive $json) {
	echo "SUCCESS: Json promise resolved!", PHP_EOL;
	echo "PHP.Gt has the following repositories: ";
	$repoList = [];
	/** @var JsonKvpObject $item */
	foreach($json->getPrimitiveValue() as $item) {
		array_push($repoList, $item->getString("name"));
	}

	echo wordwrap(implode(", ", $repoList)) . ".";
	echo PHP_EOL, PHP_EOL;
};
$errorHandler = function(Throwable $reason) {
	echo "ERROR: ", PHP_EOL, $reason->getMessage(), PHP_EOL, PHP_EOL;
};

$http = new Http();

$http->fetch("https://api.github.com/orgs/phpgt/repos")
	->then($getJsonFromResponse)
	->then($listReposFromJson)
	->catch($errorHandler);

$http->fetch("https://github.com/phpgt/__this-does-not-exist")
	->then($getJsonFromResponse)
	->then($listReposFromJson)
	->catch($errorHandler);

$http->fetch("https://cataas.com/cat")
	->then(function(Response $response) {
		return $response->blob();
	})
	->then(function(Blob $blob) {
		$file = new SplFileObject("/tmp/cat", "w");
		$bytesWritten = $file->fwrite($blob);
		echo "Written $bytesWritten bytes.", PHP_EOL;
		echo "Type: $blob->type", PHP_EOL;
		echo "Photo written to ", $file->getPathname(), PHP_EOL, PHP_EOL;
	});

$http->wait();
