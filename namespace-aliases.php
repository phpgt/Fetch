<?php

$aliasList = [
	"Gt\\Async\\Loop" => "GT\\Async\\Loop",
	"Gt\\Async\\Timer\\PeriodicTimer" => "GT\\Async\\Timer\\PeriodicTimer",
	"Gt\\Async\\Timer\\Timer" => "GT\\Async\\Timer\\Timer",
	"Gt\\Curl\\Curl" => "GT\\Curl\\Curl",
	"Gt\\Curl\\CurlException" => "GT\\Curl\\CurlException",
	"Gt\\Curl\\CurlInterface" => "GT\\Curl\\CurlInterface",
	"Gt\\Curl\\CurlMulti" => "GT\\Curl\\CurlMulti",
	"Gt\\Curl\\CurlMultiInterface" => "GT\\Curl\\CurlMultiInterface",
	"Gt\\Http\\Blob" => "GT\\Http\\Blob",
	"Gt\\Http\\File" => "GT\\Http\\File",
	"Gt\\Http\\FormData" => "GT\\Http\\FormData",
	"Gt\\Http\\Header\\Parser" => "GT\\Http\\Header\\Parser",
	"Gt\\Http\\Header\\RequestHeaders" => "GT\\Http\\Header\\RequestHeaders",
	"Gt\\Http\\Request" => "GT\\Http\\Request",
	"Gt\\Http\\RequestMethod" => "GT\\Http\\RequestMethod",
	"Gt\\Http\\Response" => "GT\\Http\\Response",
	"Gt\\Http\\Uri" => "GT\\Http\\Uri",
	"Gt\\Json\\JsonKvpObject" => "GT\\Json\\JsonKvpObject",
	"Gt\\Json\\JsonObject" => "GT\\Json\\JsonObject",
	"Gt\\Json\\JsonPrimitive\\JsonArrayPrimitive" => "GT\\Json\\JsonPrimitive\\JsonArrayPrimitive",
	"Gt\\Promise\\Deferred" => "GT\\Promise\\Deferred",
	"Gt\\Promise\\Promise" => "GT\\Promise\\Promise",
	"Gt\\Promise\\PromiseInterface" => "GT\\Promise\\PromiseInterface",
];

foreach($aliasList as $old => $new) {
	$oldExists = class_exists($old)
		|| interface_exists($old)
		|| trait_exists($old)
		|| function_exists("enum_exists") && enum_exists($old);

	if($oldExists && !class_exists($new) && !interface_exists($new) && !trait_exists($new)) {
		class_alias($old, $new);
	}
}
