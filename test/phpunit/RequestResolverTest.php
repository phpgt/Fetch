<?php
namespace GT\Fetch\Test;

use GT\Fetch\FetchException;
use GT\Fetch\RequestResolver;
use GT\Fetch\Test\Helper\NativeHandleTestCurl;
use GT\Fetch\Test\Helper\TestCurlMulti;
use Gt\Async\Loop;
use Gt\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class RequestResolverTest extends TestCase {
	public function testWriteHeaderRejectsRedirectsForNativeCurlHandle():void {
		$sut = new RequestResolver(
			new Loop(),
			NativeHandleTestCurl::class,
			TestCurlMulti::class,
		);
		$curl = new NativeHandleTestCurl("test://should-redirect");
		$response = new Response();
		$response->startDeferredResponse($curl);

		$this->setProperty($sut, "curlList", [$curl]);
		$this->setProperty($sut, "responseList", [$response]);
		$this->setProperty($sut, "headerList", [""]);
		$this->setProperty($sut, "maxRedirectsList", [0]);
		$this->setProperty($sut, "signalList", [null]);

		$writeHeader = new ReflectionMethod($sut, "writeHeader");

		self::expectException(FetchException::class);
		self::expectExceptionMessage("Redirect is disallowed");
		$writeHeader->invoke($sut, $curl->getHandle(), "Location: /redirected\r\n");
	}

	private function setProperty(
		RequestResolver $requestResolver,
		string $property,
		array $value,
	):void {
		$reflectionProperty = new ReflectionProperty($requestResolver, $property);
		$reflectionProperty->setValue($requestResolver, $value);
	}
}
