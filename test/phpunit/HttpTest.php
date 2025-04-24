<?php
namespace Gt\Fetch\Test;

use Gt\Fetch\FetchException;
use Gt\Fetch\Http;
use Gt\Fetch\Test\Helper\ResponseSimulator;
use Gt\Fetch\Test\Helper\TestCurl;
use Gt\Fetch\Test\Helper\TestCurlMulti;
use Gt\Http\Request;
use Gt\Http\Response;
use Gt\Http\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Throwable;

class HttpTest extends TestCase {
	public function testFetchBodyResponsePromise() {
		$http = new Http(
			[],
			0.01,
			TestCurl::class,
			TestCurlMulti::class
		);

		$fakeStatus = null;

		$http->fetch("test://should-return")
		->then(function(Response $response)use(&$fakeStatus) {
			$fakeStatus = $response->status;
		});

		$http->wait();
		self::assertEquals(999, $fakeStatus);
	}

	public function testFetchBodyResponsePromiseResolves() {
		$expectedHtml = "<!doctype html><h1>Hello, Fetch!</h1>";
		ResponseSimulator::setExpectedBody($expectedHtml);
		$http = new Http(
			[],
			0.01,
			TestCurl::class,
			TestCurlMulti::class
		);

		$actualResponse = null;

		$http->fetch("test://should-return")
		->then(function(Response $response)use(&$fakeStatus) {
			return $response->text();
		})
		->then(function(string $text)use(&$actualResponse) {
			$actualResponse = $text;
		});

		$http->wait();
		self::assertEquals($expectedHtml, $actualResponse);

	}

	public function testGetOptions() {
		$options = [
			uniqid() => uniqid(),
			uniqid() => uniqid(),
			uniqid() => uniqid(),
			uniqid() => uniqid(),
			uniqid() => uniqid(),
		];
		$http = new Http($options);
		$actualOptions = $http->curlOptions;

		foreach($options as $key => $value) {
			self::assertEquals($value, $actualOptions[$key]);
		}

		self::assertGreaterThan(count($options), count($actualOptions));
	}

	/** @runInSeparateProcess */
	public function testAll() {
		$http = new Http(
			[],
			0.01,
			TestCurl::class,
			TestCurlMulti::class
		);

		$actualResponse = null;

		$http->fetch("test://should-return")
			->then(function(Response $response)use(&$fakeStatus) {
				return $response->text();
			})
			->then(function(string $text)use(&$actualResponse) {
				$actualResponse = $text;
			});

		$resolutionTime = null;

		$prom = $http->all();
		$prom->then(function(float $dt) use(&$resolutionTime) {
			$resolutionTime = $dt;
		});

		self::assertNotNull($actualResponse);
		self::assertNotNull($resolutionTime);
		self::assertGreaterThanOrEqual(0, $resolutionTime);
	}

	public function testEnsureUriInterface() {
		$sut = new Http();
		$uri = $sut->ensureUriInterface("test://example");
		self::assertInstanceOf(UriInterface::class, $uri);

		$uriInterface = self::createMock(Uri::class);
		$uriInterface->method("__toString")
			->willReturn("test://example");
		$request = self::createMock(Request::class);
		$request->method("getUri")
			->willReturn($uriInterface);
		$uri = $sut->ensureUriInterface($request);
		self::assertInstanceOf(UriInterface::class, $uri);
	}

	public function testFetchRedirectError():void {
		$actualResolution = null;
		$actualRejection = null;

		$sut = new Http(
			[],
			0.01,
			TestCurl::class,
			TestCurlMulti::class
		);
		$sut->fetch("test://should-redirect", [
			"redirect" => "error"
		])->then(function(mixed $resolution) use(&$actualResolution) {
			$actualResolution = $resolution;
		})->catch(function(Throwable $rejection) use(&$actualRejection) {
			$actualRejection = $rejection;
		});

		self::expectException(FetchException::class);
		self::expectExceptionMessage("Redirect is disallowed");
		$sut->wait();

		self::assertNull($actualResolution);
		self::assertInstanceOf(FetchException::class, $actualRejection);
	}

	/** @runInSeparateProcess */
	public function testAwaitFetch():void {
		$http = new Http(
			[],
			0.01,
			TestCurl::class,
			TestCurlMulti::class
		);

		$response = $http->awaitFetch("test://should-return");
		$text = $response->awaitText();

		self::assertNotNull($response);
		self::assertGreaterThan(0, strlen($text));
	}
}
