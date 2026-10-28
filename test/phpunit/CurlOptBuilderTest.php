<?php
namespace GT\Fetch\Test;

use GT\Fetch\AbortController;
use GT\Fetch\CurlOptBuilder;
use GT\Fetch\FetchException;
use GT\Fetch\NotAvailableServerSideException;
use GT\Fetch\UnknownCurlOptException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class CurlOptBuilderTest extends TestCase {
	/** @return array<string, array{array<string, mixed>}> */
	public static function invalidHeaderProvider():array {
		$cases = [];
		foreach(["\r", "\n", "\r\n", "\0"] as $character) {
			$label = bin2hex($character);
			$cases["header name $label"] = [["headers" => ["X-$character-Injected" => "1"]]];
			$cases["header value $label"] = [["headers" => ["X-User" => "alice{$character}X-Injected: 1"]]];
			$cases["array value $label"] = [["headers" => ["X-User" => ["alice", "bob{$character}X-Injected: 1"]]]];
			$cases["referrer $label"] = [["referrer" => "http://example.com/{$character}X-Injected: 1"]];
		}
		foreach(["", "X User", "X:User", "X\tUser", "X( User)", "X-\x80"] as $name) {
			$cases["invalid name " . bin2hex($name)] = [["headers" => [$name => "1"]]];
		}
		return $cases;
	}

	#[DataProvider("invalidHeaderProvider")]
	public function testRejectInvalidHeaders(array $init):void {
		self::expectException(FetchException::class);
		new CurlOptBuilder(null, $init);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalidRequestHeaderProvider():array {
		return array_filter(self::invalidHeaderProvider(), fn(array $case) => isset($case[0]["headers"]));
	}

	#[DataProvider("invalidRequestHeaderProvider")]
	public function testRejectInvalidRequestHeaders(array $init):void {
		$request = self::createStub(RequestInterface::class);
		$request->method("getMethod")->willReturn("GET");
		$request->method("getHeaders")->willReturn($init["headers"]);
		self::expectException(FetchException::class);
		new CurlOptBuilder($request);
	}

	public function testValidHeaderCharacters():void {
		$name = "X-!#$%&'*+-.^_`|~0123456789";
		$sut = new CurlOptBuilder(null, [
			"headers" => [$name => ["value\twith spaces", "", "\x80"]],
			"referrer" => "https://example.com/path?query=value",
		]);
		self::assertSame([
			"$name: value\twith spaces, , \x80",
		], $sut->asCurlOptArray()[CURLOPT_HTTPHEADER]);
		self::assertSame(
			"https://example.com/path?query=value",
			$sut->asCurlOptArray()[CURLOPT_REFERER],
		);
	}

	public function testInvalidKey() {
		self::expectException(UnknownCurlOptException::class);
		$sut = new CurlOptBuilder(null, [
			"unknown" => "nothing",
		]);
	}

	public function testAsCurlOptArrayEmpty() {
		$sut = new CurlOptBuilder(null, []);
		$curlOptArray = $sut->asCurlOptArray();
		self::assertEquals([], $curlOptArray);
	}

	public function testSetMethod() {
		$sut = new CurlOptBuilder(null, [
			"method" => "POST",
		]);
		self::assertEquals([
			CURLOPT_CUSTOMREQUEST => "POST",
		], $sut->asCurlOptArray());
	}

	public function testSetHeaders() {
		$headersArray = [
			"User-Agent" => "PHPUnit",
			"X-Project" => "phpgt/fetch",
		];

		$sut = new CurlOptBuilder(null, [
			"headers" => $headersArray
		]);

		$expectedCurlHeaders = [];
		foreach($headersArray as $key => $value) {
			$expectedCurlHeaders []= "$key: $value";
		}
		self::assertEquals(
			$expectedCurlHeaders,
			$sut->asCurlOptArray()[CURLOPT_HTTPHEADER]
		);
	}

	public function testSetBody() {
		$bodyData = [
			"param1" => "value1",
			"param2" => "value2",
		];

		$sut = new CurlOptBuilder(null, [
			"body" => $bodyData,
		]);
		self::assertEquals(
			$bodyData,
			$sut->asCurlOptArray()[CURLOPT_POSTFIELDS]
		);
	}

	public function testSetMode() {
		self::expectException(NotAvailableServerSideException::class);
		$sut = new CurlOptBuilder(null, [
			"mode" => "cors"
		]);
	}

	public function testSetCredentials() {
		self::expectException(NotAvailableServerSideException::class);
		$sut = new CurlOptBuilder(null, [
			"credentials" => "same-origin"
		]);
	}

	public function testSetRedirect() {
		$sut = new CurlOptBuilder(null, [
			"redirect" => "follow",
		]);
		self::assertTrue(
			$sut->asCurlOptArray()[CURLOPT_FOLLOWLOCATION]
		);

		$sut = new CurlOptBuilder(null, [
			"redirect" => "manual",
		]);
		self::assertFalse(
			$sut->asCurlOptArray()[CURLOPT_FOLLOWLOCATION]
		);
	}

	public function testReferrer() {
		$sut = new CurlOptBuilder(null, [
			"referrer" => "here",
		]);
		self::assertEquals(
			"here",
			$sut->asCurlOptArray()[CURLOPT_REFERER]
		);

		$sut = new CurlOptBuilder(null, [
			"referrer" => "no-referrer",
		]);
		self::assertEquals(
			"",
			$sut->asCurlOptArray()[CURLOPT_REFERER]
		);
	}

	public function testSetReferrerPolicy() {
		self::expectException(NotAvailableServerSideException::class);
		$sut = new CurlOptBuilder(null, [
			"referrerPolicy" => "cross-origin"
		]);
	}

	public function testSetIntegrity() {
		$hash = "sha1-aabbccddeeff";
		$sut = new CurlOptBuilder(null, [
			"integrity" => $hash,
		]);
		self::assertEquals($hash, $sut->getIntegrity());
	}

	public function testSetKeepalive() {
		$sut = new CurlOptBuilder(null, [
			"keepalive" => 123,
		]);
		self::assertEquals(
			123,
			$sut->asCurlOptArray()[CURLOPT_TCP_KEEPALIVE]
		);
	}

	public function testSetSignal() {
		$obj = self::createMock(AbortController::class);
		$sut = new CurlOptBuilder(null, [
			"signal" => $obj,
		]);
		self::assertEquals($obj, $sut->getSignal());
	}
}
