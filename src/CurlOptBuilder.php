<?php
namespace GT\Fetch;

use CURLFile;
use GT\Http\File;
use GT\Http\FormData;
use GT\Http\RequestMethod;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Converts a Fetch Init array to CURLOPT_* key-values.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/WindowOrWorkerGlobalScope/fetch#Syntax
 */
class CurlOptBuilder {
	/** @var array<string, int|string> */
	protected array $curlOptArray;
	protected ?string $integrity;
	protected ?Controller $signal;

	/** @param array<string, int|string> $init */
	public function __construct(
		null|string|UriInterface|RequestInterface $input,
		array $init = [],
	) {
		$this->curlOptArray = [];

		if($input instanceof RequestInterface) {
			$this->fromRequestObject($input);
		}

		foreach($init as $key => $value) {
			$function = "set" . ucfirst($key);
			if(method_exists($this, $function)) {
				call_user_func([$this, $function], $value);
			}
			else {
				throw new UnknownCurlOptException($key);
			}
		}
	}

	/** @return array<string, int|string> */
	public function asCurlOptArray():array {
		return $this->curlOptArray;
	}

	protected function fromRequestObject(RequestInterface $request):void {
		$this->curlOptArray[CURLOPT_URL] = (string)$request->getUri();

		if($method = $request->getMethod()) {
			$this->setMethod($method);
		}

		$this->setHeaders($request->getHeaders());
	}

	/**
	 * @param string $value The request method, e.g., GET, POST.
	 * @SuppressWarnings("StaticAccess")
	 */
	protected function setMethod(string $value):void {
		$this->curlOptArray[CURLOPT_CUSTOMREQUEST] =
			RequestMethod::filterMethodName($value);
	}

	/**
	 * @param array<string, string|string[]> $headers Any headers you want
	 * to add to your request, contained within an associative array.
	 */
	protected function setHeaders(array $headers):void {
		$rawHeaders = [];

		foreach($headers as $key => $value) {
			$headerLine = "$key: ";
			if(!is_array($value)) {
				$value = [$value];
			}

			$headerLine .= implode(", ", $value);
			$rawHeaders []= $headerLine;
		}

		$this->curlOptArray[CURLOPT_HTTPHEADER] = $rawHeaders;
	}

	/**
	 * @param string|array<string, string> $body Any body that you want to
	 * add to your request: this can be a string, associative array
	 * (representing form data) or binary object.
	 */
	protected function setBody(string|array|FormData $body):void {
		if($body instanceof FormData) {
			$formData = $body;
			$body = [];
			foreach($formData as $key => $value) {
				if($value instanceof File) {
					$value = new CURLFile($value->name);
				}
				$body[$key] = $value;
			}
		}
		$this->curlOptArray[CURLOPT_POSTFIELDS] = $body;
	}

	/**
	 * @param string $value The mode you want to use for the request,
	 * e.g., cors, no-cors, or same-origin
	 */
	protected function setMode(string $value):void {
		throw new NotAvailableServerSideException("mode: $value");
	}

	/**
	 * @param string $value The request credentials you want to use for the
	 * request: omit, same-origin, or include. To automatically send
	 * cookies for the current domain, this option must be provided.
	 */
	protected function setCredentials(string $value):void {
		throw new NotAvailableServerSideException("credentials: $value");
	}

	/**
	 * @param string $value The cache mode you want to use for the request.
	 * @SuppressWarnings("UnusedFormalParameter")
	 * @see https://developer.mozilla.org/en-US/docs/Web/API/Request/cache
	 */
	protected function setCache(string $value):void {
// TODO: Throw exception until caching is implemented.
	}

	/**
	 * @param string $value The redirect mode to use: follow (automatically
	 * follow redirects) or manual (handle redirects manually).
	 */
	protected function setRedirect(string $value):void {
		$value = strtolower($value);

		if($value === "follow" || $value === "manual") {
			$this->curlOptArray[CURLOPT_FOLLOWLOCATION] = $value === "follow";
		}
		elseif($value === "error") {
			$this->curlOptArray[CURLOPT_MAXREDIRS] = 0;
		}
	}

	/**
	 * @param string $value A string specifying no-referrer, client,
	 * or a URL.
	 */
	protected function setReferrer(string $value):void {
		switch(strtolower($value)) {
		case "no-referrer":
			$this->curlOptArray[CURLOPT_REFERER] = null;
			break;

		default:
			$this->curlOptArray[CURLOPT_REFERER] = $value;
			break;
		}
	}

	/**
	 * @param string $value Specifies the value of the referer HTTP header.
	 * May be one of no-referrer, no-referrer-when-downgrade, origin,
	 * origin-when-cross-origin, unsafe-url.
	 */
	protected function setReferrerPolicy(string $value):void {
		throw new NotAvailableServerSideException("referrerPolicy: $value");
	}

	/**
	 * @param string $value Contains the subresource integrity value of the
	 * request (e.g., sha256-BpfBw7ivV8q2jLiT13fxDYAe2tJllusRSZ273h2nFSE=).
	 */
	protected function setIntegrity(string $value):void {
		$this->integrity = $value;
	}

	public function getIntegrity():?string {
		return $this->integrity ?? null;
	}

	protected function setKeepalive(string $value):void {
		$this->curlOptArray[CURLOPT_TCP_KEEPALIVE] = (int)$value;
	}

	protected function setSignal(Controller $value):void {
		$this->signal = $value;
	}

	public function getSignal():?Controller {
		return $this->signal ?? null;
	}
}
