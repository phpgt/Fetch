<?php
require(implode(DIRECTORY_SEPARATOR, ["..", "vendor", "autoload.php"]));

use Gt\Fetch\Http;
use Gt\Http\FormData;
use Gt\Http\Response;
use Gt\Json\JsonObject;

/*
 * This example uses postman-echo.com do test the request/response.
 * See https://docs.postman-echo.com/ for more information.
 */

// Example: Post form data to the echo server.

$formData = new FormData();
$formData->set("name", "Mark Zuckerberg");
$formData->set("dob", "1984-05-14");
$formData->set("email", "zuck@fb.com");

$http = new Http();
$http->fetch("https://postman-echo.com/post", [
// All of the request parameters can be passed directly here, or alternatively
// the fetch() function can take a PSR-7 RequestInterface object.
	"method" => "POST",
	"body" => $formData,
])
	->then(function(Response $response) {
		if(!$response->ok) {
			throw new RuntimeException("Error posting to Postman Echo.");
		}
// Postman Echo servers respond with a JSON representation of the request
// that was received.
		return $response->json();
	})
	->then(function(JsonObject $json) {
		echo "The Postman Echo server received the following form fields:";
		echo PHP_EOL;

		$formObject = $json->getObject("form");
		foreach($formObject->asArray() as $key => $value) {
			echo "$key = $value" . PHP_EOL;
		}

		$firstName = strtok($formObject->getString("name"), " ");
		$dob = $formObject->getDateTime("dob");
		$age = date("Y") - $dob->format("Y");
		echo PHP_EOL;
		echo "$firstName is $age years old!" . PHP_EOL;
	})
	->catch(function(Throwable $error) {
		echo "An error occurred: ", $error->getMessage();
	});

// To execute the above Promise(s), call wait() or all().
$http->wait();
die("done waiting");
