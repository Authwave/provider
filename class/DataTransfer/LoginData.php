<?php
namespace Authwave\DataTransfer;

class LoginData {
	const SESSION_LOGIN_DATA = "authwave.loginData";

	const TYPE_PASSWORD = "password";
	const TYPE_EMAIL = "email";
	const TYPE_SOCIAL = "social";

	const SOCIAL_GOOGLE = "social-google";
	const SOCIAL_TWITTER = "social-twitter";
	const SOCIAL_FACEBOOK = "social-facebook";
	const SOCIAL_LINKEDIN = "social-linkedin";
	const SOCIAL_GITHUB = "social-github";
	const SOCIAL_MICROSOFT = "social-microsoft";

	private string $email;

	public function __construct(string $email) {
		$this->email = $email;
	}

	public function getEmail():string {
		return $this->email;
	}
}