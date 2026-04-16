<?php
require(implode(DIRECTORY_SEPARATOR, ["..", "vendor", "autoload.php"]));

use GT\Fetch\Http;
use GT\Http\Response;
use GT\Json\JsonKvpObject;
use GT\Json\JsonPrimitive\JsonArrayPrimitive;

$http = new Http();

$http->fetch("https://api.github.com/orgs/phpgt/repos")
//$http->fetch("https://raw.githubusercontent.com/PhpGt/Fetch/master/broken.json")
	->then(function(Response $response) {
		echo "Got a response. Processing JSON... ", PHP_EOL;

		if(!$response->ok) {
			throw new RuntimeException("Can't retrieve Github's API on $response->uri");
		}

		return $response->json();
	})
	->then(function(JsonArrayPrimitive $json) {
		echo "SUCCESS: Json promise resolved!", PHP_EOL, PHP_EOL;
		echo "PHP.Gt has the following repositories: ";
		$repoList = [];
		/** @var JsonKvpObject $item */
		foreach($json->getPrimitiveValue() as $item) {
			array_push($repoList, $item->getString("name"));
		}

		echo wordwrap(implode(", ", $repoList)) . ".";
		echo PHP_EOL, PHP_EOL;
	})
	->catch(function(Throwable $reason) {
		echo "There was an error!",
			PHP_EOL,
			get_class($reason),
			" - ",
			$reason->getMessage(),
			" ",
			$reason->getFile(),
			":", $reason->getLine(),
			PHP_EOL, PHP_EOL;
	});

$http->wait();
