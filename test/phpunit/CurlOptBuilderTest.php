<?php
namespace GT\Fetch\Test;

use GT\Fetch\AbortController;
use GT\Fetch\CurlOptBuilder;
use GT\Fetch\NotAvailableServerSideException;
use GT\Fetch\UnknownCurlOptException;
use PHPUnit\Framework\TestCase;

class CurlOptBuilderTest extends TestCase {
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
