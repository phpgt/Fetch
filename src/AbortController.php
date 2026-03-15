<?php
namespace GT\Fetch;

class AbortController extends Controller {
	public self $signal;
	public bool $aborted;

	public function __construct() {
		$this->signal = $this;
		$this->aborted = false;
	}

	public function abort():void {
		$this->aborted = true;
	}
}
