<?php
namespace Authwave\Model;

use GT\Http\Uri;
use Psr\Http\Message\UriInterface;

readonly class ApplicationDeployment {
	public string $applicationId;

	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function __construct(
		public string $id,
		public Application $application,
		public string $title,
		public string $secret,
		public string $clientHost,
		public string $clientLoginPath,
		public string $providerHost = "",
		public bool $domainVerified = false,
		public bool $clientConnected = false,
		public bool $userLoggedIn = false,
		public ?string $emailPrefix = null,
		public ?string $emailReplyTo = null,
		public ?string $emailFromName = null,
	) {
		$this->applicationId = $application->id;
	}

	public function getClientReturnUri():UriInterface {
		$scheme = "https";

		$host = $this->clientHost;
		$schemeSeparator = strpos($host, "://");

		if($schemeSeparator !== false) {
			$scheme = strtok($host, "://");
			$host = substr($host, $schemeSeparator + 3);
		}

		return (new Uri())
			->withScheme($scheme)
			->withHost($host)
			->withPath($this->clientLoginPath);
	}

	public function getApiKey():string {
		$prefix = str_replace("_", "-", mb_strtoupper($this->title));
		$separator = strpos($this->id, "_");
		$suffix = $separator === false ? "_" . $this->id : substr($this->id, $separator);
		return $prefix . $suffix . "_" . $this->secret;
	}

	public function getSenderName(?string $fallback = null):string {
		// Older serialised sessions may not contain the sender name field.
		return get_object_vars($this)["emailFromName"] ?? $fallback ?? $this->application->name;
	}

	public function getSenderAddress():?string {
		$prefix = get_object_vars($this)["emailPrefix"] ?? null;
		if($prefix === null) {
			return $this->application->emailSendFrom;
		}
		$host = (new Uri("https://" . $this->getProviderHost()))->getHost();
		return "$prefix@$host";
	}

	public function getProviderHost():string {
		// Old serialised sessions may lack this field entirely.
		return get_object_vars($this)["providerHost"] ?? "";
	}
}
