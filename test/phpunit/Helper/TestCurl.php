<?php
namespace GT\Fetch\Test\Helper;

use GT\Curl\Curl;

class TestCurl extends Curl {
	protected string $id;
	private int $maxRedirs = -1;

	public function __construct(private ?string $url = null) {
		$this->id = uniqid("dummy-curl-");
		$this->ch = curl_init();
		parent::__construct($url);
	}

	public function setOpt(int $option, $value):bool {
		if($option === CURLOPT_HEADERFUNCTION) {
			ResponseSimulator::setHeaderCallback($value);
		}

		if($option === CURLOPT_WRITEFUNCTION) {
			ResponseSimulator::setBodyCallback($value);
		}

		if($option === CURLOPT_MAXREDIRS) {
			$this->maxRedirs = $value;
		}

		return true;
	}

	public function getHandle() {
		return $this;
	}

	public function getInfo(int $opt): mixed {
		return match($opt) {
			CURLINFO_EFFECTIVE_URL => $this->url,
			CURLOPT_MAXREDIRS => $this->maxRedirs,
			default => null
		};
	}
}
