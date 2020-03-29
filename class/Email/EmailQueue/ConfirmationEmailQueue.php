<?php
namespace Authwave\Email\EmailQueue;

class ConfirmationEmailQueue extends AbstractEmailQueue {
	private string $name;
	private string $code;
	private string $providerHost;

	public function setClientName(string $name):void {
		$this->name = $name;
	}

	public function setCode(string $code):void {
		$this->code = $code;
	}

	public function setProviderHost(string $providerHost):void {
		$this->providerHost = $providerHost;
	}

	protected function getSubject():string {
		return $this->name . " account confirmation";
	}

	protected function getBodyText():string {
		return "Your confirmation code is " . $this->code;
	}

	protected function getBodyHtml():string {
		return $this->getBodyText();
	}
}