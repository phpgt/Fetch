<?php
namespace GT\Fetch\Test\Helper;

use GT\Curl\CurlInterface;

class ResponseSimulator {
	const RANDOM_BODY_WORDS = ["pursuit","forest","gravel","timber","wonder","eject","slogan","monkey","construct","earthquake","respect","publish","forward","circle","summer","define","highlight","refuse","salon","theater","lily","earwax","variant","account","resource"];
	static protected $headerCallback;
	static protected $bodyCallback;
	static protected $headerBuffer;
	static protected $bodyBuffer;
	static protected $started = false;
	/**
	 * @var true
	 */
	private static bool $redirectAdded = false;

	static public function setHeaderCallback(callable $callback) {
		self::$headerCallback = $callback;
	}

	static public function setBodyCallback(callable $callback) {
		self::$bodyCallback = $callback;
	}

	static public function start() {
		self::$started = true;
		self::$redirectAdded = false;
		self::$headerBuffer = self::generateHeaders();
		self::$bodyBuffer = self::generateBody();
	}

	static protected function generateHeaders():array {
		$headers = [];

		$headers []= "HTTP/0.0 999 OK";
		$headers []= "Date: " . date("D, d M Y H:i:s T");
		$headers []= "Repository: PhpGt/Fetch";

		$length = rand(1, 10);
		for($i = 0; $i < $length; $i++) {
			$randIndex = array_rand(self::RANDOM_BODY_WORDS);
			$key = self::RANDOM_BODY_WORDS[$randIndex];
			$value = uniqid();
			$headers []= "$key: $value";
		}

		foreach($headers as $i => $h) {
			$headers[$i] .= "\r\n";
		}

		$headers []= "\r\n";

		return $headers;
	}

	static protected function generateBody():string {
		if(strlen(self::$bodyBuffer ?? "") > 0) {
			return self::$bodyBuffer;
		}

		$body = "";
		$length = rand(10, 100);
		for($i = 0; $i < $length; $i++) {
			$randIndex = array_rand(self::RANDOM_BODY_WORDS);
			$body .= self::RANDOM_BODY_WORDS[$randIndex];
			$body .= " ";
		}

		return $body;
	}

	static public function hasStarted():bool {
		return self::$started;
	}

	static public function sendChunk(CurlInterface $ch):int {
		$url = $ch->getInfo(CURLINFO_EFFECTIVE_URL);
		if($url === "test://should-follow-redirect" && !self::$redirectAdded) {
			self::$headerBuffer = [
				"HTTP/1.1 303 See Other\r\n",
				"Content-Type: text/html\r\n",
				"Location: /redirected\r\n",
				"\r\n",
				"HTTP/1.1 200 OK\r\n",
				"Content-Type: application/json\r\n",
				"X-Final-Response: true\r\n",
				"\r\n",
			];
			self::$bodyBuffer = "{}";
			self::$redirectAdded = true;
		}
		if($url === "test://should-redirect") {
			$locationRedirect = "Location: /redirected\r\n";
			if(!self::$redirectAdded) {
				array_splice(self::$headerBuffer, 1, 0, $locationRedirect);
				self::$redirectAdded = true;
			}
		}
		if(!empty(self::$headerBuffer)) {
			$data = array_shift(self::$headerBuffer);

			call_user_func(
				self::$headerCallback,
				$ch->getHandle(),
				$data
			);

			return 1;
		}
		elseif(!empty(self::$bodyBuffer)) {
			$data = self::$bodyBuffer;
			self::$bodyBuffer = "";

			call_user_func(
				self::$bodyCallback,
				$ch->getHandle(),
				$data
			);

			return 1;
		}
		else {
			self::$started = false;
			return 0;
		}
	}

	static public function setExpectedBody(string $body) {
		self::$bodyBuffer = $body;
	}
}
