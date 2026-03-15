<?php
namespace Gt\Fetch;

use CurlHandle;
use Gt\Async\Loop;
use Gt\Curl\CurlException;
use Gt\Curl\CurlInterface;
use Gt\Curl\CurlMultiInterface;
use Gt\Http\Header\Parser;
use Gt\Http\Response;
use Gt\Promise\Deferred;
use Psr\Http\Message\UriInterface;

class RequestResolver {
	/** @var array<CurlMultiInterface|null> */
	private array $curlMultiList;
	/** @var array<CurlInterface|null> */
	private array $curlList;
	/** @var array<Deferred|null> */
	private array $deferredList;
	/** @var array<Response|null> */
	private array $responseList;
	/** @var array<string|null> */
	private array $headerList;
	/** @var array<string|null> */
	private array $integrityList;
	/** @var array<object|null> */
	private array $signalList;

	public function __construct(
		private readonly Loop $loop,
		private readonly string $curlClass,
		private readonly string $curlMultiClass,
	) {
		$this->curlMultiList = [];
		$this->curlList = [];
		$this->deferredList = [];
		$this->responseList = [];
		$this->headerList = [];
		$this->integrityList = [];
		$this->signalList = [];
	}

	/**
	 * Adds a new job to resolve. An HTTP request will be made to the
	 * provided UriInterface via curl_multi, using the provided options
	 * array. The Deferred object will be used to pass back the resolved
	 * BodyResponse, or rejected Throwable.
	 * The optional $integrity value will match the response's body with the
	 * provided hash as Subresource Identity (SRI).
	 * The optional Controller $signal is used internally by curl to provide
	 * an abort signal.
	 * @param array<int, int|string> $curlOptArray
	 */
	public function add(
		UriInterface $uri,
		array $curlOptArray,
		Deferred $deferred,
		?string $integrity = null,
		?Controller $signal = null,
	):void {
		/** @var CurlInterface $curl */
		$curl = new $this->curlClass($uri);

// curlopt1: Set the default curlopt values here:
		$curl->setOpt(CURLOPT_USERAGENT, Http::USER_AGENT);

// curlopt2: Then override any curlopt values that are provided:
		foreach($curlOptArray as $option => $value) {
			$curl->setOpt($option, $value);
		}

// curlopt3: Finally, hard-code these curlopt settings:
		$curl->setOpt(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		$curl->setOpt(CURLOPT_RETURNTRANSFER, false);
		$curl->setOpt(CURLOPT_HEADER, false);
		$curl->setOpt(CURLOPT_HEADERFUNCTION, $this->writeHeader(...));
		$curl->setOpt(CURLOPT_WRITEFUNCTION, $this->writeBody(...));
		$curl->setOpt(CURLOPT_PROGRESSFUNCTION, $this->progress(...));
		$curl->setOpt(CURLOPT_NOPROGRESS, false);

		/** @var CurlMultiInterface $curlMulti */
		$curlMulti = new $this->curlMultiClass();
		$curlMulti->add($curl);

		$this->loop->addDeferredToTimer($deferred);

		$bodyResponse = new Response();
		$bodyResponse->startDeferredResponse($curl);

		array_push($this->curlList, $curl);
		array_push($this->curlMultiList, $curlMulti);
		array_push($this->deferredList, $deferred);
		array_push($this->integrityList, $integrity);
		array_push($this->responseList, $bodyResponse);
		array_push($this->headerList, "");
		array_push($this->signalList, $signal);
	}

	public function tick():void {
		$totalActive = 0;

		foreach($this->curlMultiList as $i => $curlMulti) {
			$this->processCurlMulti($curlMulti, $i, $totalActive);
		}

		if($totalActive === 0) {
			$this->loop->halt();
		}
	}

	private function processCurlMulti(
		CurlMultiInterface $curlMulti,
		int $index,
		int &$totalActive,
	):void {
		$active = 0;

		$this->executeCurlMultiLoop($curlMulti, $active);

		if($active === 0) {
			$this->handleCompletedCurlMulti($index);
		}
		else {
			$totalActive += $active;
		}
	}

	private function executeCurlMultiLoop(
		CurlMultiInterface $curlMulti,
		int &$active,
	):void {
		do {
			$status = $curlMulti->exec($active);
		}
		while($status === CURLM_CALL_MULTI_PERFORM);

		if($status !== CURLM_OK) {
			$this->handleCurlMultiError($curlMulti);
		}
	}

	private function handleCurlMultiError(CurlMultiInterface $curlMulti):void {
		$errNo = curl_multi_errno($curlMulti->getHandle());
		$errString = curl_multi_strerror($errNo);
		throw new CurlException($errString);
	}

	private function handleCompletedCurlMulti(int $index):void {
		$response = $this->responseList[$index] ?? null;

		if($response) {
			$response->endDeferredResponse($this->integrityList[$index]);

			if($this->deferredList[$index]) {
				$this->deferredList[$index]->resolve($response);
			}

			$this->cleanupCompletedRequestData($index);
		}
	}

	private function cleanupCompletedRequestData(int $index):void {
		$this->responseList[$index] = null;
		$this->deferredList[$index] = null;
	}


	private function writeHeader(
		CurlHandle|CurlInterface $ch,
		string $rawHeader,
	):int {
		$i = $this->getIndex($ch);
		$headerLine = trim($rawHeader);

// If $headerLine is empty, it represents the last line before the body starts.
// HTTP headers always end on an empty line.
// See https://www.w3.org/Protocols/rfc2616/rfc2616-sec4.html
		if($headerLine === "") {
			$parser = new Parser($this->headerList[$i]);
			$this->responseList[$i] = $this->responseList[$i]->withProtocolVersion(
				$parser->getProtocolVersion()
			);
			$this->responseList[$i] = $this->responseList[$i]->withStatus(
				$parser->getStatusCode()
			);

			foreach($parser->getKeyValues() as $key => $value) {
				if(empty($key)) {
					continue;
				}

				$this->responseList[$i] = $this->responseList[$i]->withAddedHeader(
					$key,
					$value
				);
			}
		}

		if(str_starts_with(strtolower($headerLine), "location: ")) {
			if($ch->getInfo(CURLOPT_MAXREDIRS) === 0) {
				throw new FetchException("Redirect is disallowed");
			}
		}

		$this->headerList[$i] .= $rawHeader;

// To indicate that this function has successfully run, cURL expects it to
// return the number of bytes read. If this does not match the same number
// that cURL sees, cURL will drop the connection.
		return strlen($rawHeader);
	}

	private function writeBody(CurlHandle|CurlInterface $ch, string $content):int {
		$i = $this->getIndex($ch);

		$body = $this->responseList[$i]->getBody();
		$body->write($content);

		if($this->deferredList[$i]) {
			$this->deferredList[$i]->resolve($this->responseList[$i]);
			$this->deferredList[$i] = null;
		}

// To indicate that this function has successfully run, cURL expects it to
// return the number of bytes read. If this does not match the same number
// that cURL sees, cURL will drop the connection.
		return strlen($content);
	}

	/**
	 * @SuppressWarnings("UnusedFormalParameter")
	 * @noinspection PhpUnusedParameterInspection
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	private function progress(
		CurlHandle|CurlInterface $ch,
		int $expectedDownloadedBytes,
		int $downloadedBytes,
		int $expectedUploadedBytes,
		int $uploadedBytes
	):int {
		$index = $this->getIndex($ch);
		return (int)$this->signalList[$index]?->aborted;
	}

	private function getIndex(CurlHandle|CurlInterface $chIncoming):int {
		$i = -1;
		$match = false;
		foreach($this->curlList as $i => $curl) {
			if($chIncoming === $curl->getHandle()) {
				$match = true;
				break;
			}
		}

		if(!$match) {
			throw new FetchException("There is no curl handle");
		}

		return $i;
	}
}
