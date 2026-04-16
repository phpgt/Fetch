<?php
namespace GT\Fetch\Test\Helper;

use GT\Curl\CurlInterface;
use GT\Curl\CurlMulti;

class TestCurlMulti extends CurlMulti {
	protected array $curlHandleArray;

	public function add(CurlInterface $curl):void {
		array_push($this->curlHandleArray, $curl);
	}

	public function exec(int &$stillRunning):int {
		if(!ResponseSimulator::hasStarted()) {
			ResponseSimulator::start();
		}

		foreach($this->curlHandleArray as $ch) {
			$inc = ResponseSimulator::sendChunk($ch);
			if($inc > 0) {
				$stillRunning += $inc;
			}
			elseif($stillRunning > 0) {
				$stillRunning--;
			}
		}

		return CURLM_OK;
	}
}
