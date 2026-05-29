<?php
namespace GT\Fetch\Test\Helper;

class NativeHandleTestCurl extends TestCurl {
	public function getHandle() {
		return $this->ch;
	}
}
