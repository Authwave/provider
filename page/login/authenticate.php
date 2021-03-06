<?php
namespace Authwave\Page\Login;

use Authwave\Application\ApplicationDeployment;
use Authwave\Crypto\AuthUriFactory;
use Authwave\DataTransfer\LoginData;
use Authwave\DataTransfer\RequestData;
use Authwave\Email\ConfirmationCode;
use Authwave\Email\EmailQueue\ConfirmationEmailQueue;
use Authwave\Password\PasswordTooShortException;
use Authwave\Password\Strengthometer;
use Authwave\UI\Flash;
use Authwave\User\LoginNeedsEmailConfirmationException;
use Authwave\User\UserRepository;
use Gt\DomTemplate\Element;
use Gt\Input\InputData\InputData;
use Gt\WebEngine\Logic\Page;
use SendGrid\Mail\Mail;
use TypeError;

class AuthenticatePage extends Page {
	public RequestData $requestData;
	public Flash $flash;
	public UserRepository $userRepo;
	public ApplicationDeployment $deployment;

	private LoginData $loginData;

	public function go():void {
		$this->restoreLoginData();
		$this->outputEmailAddress();
		$this->outputProviders(
			$this->document->querySelector(".auth-option.social")
		);
	}

	public function doPassword(InputData $data):void {
		$password = $data->getString("password");

		$strengthometer = new Strengthometer($password);
		try {
			$strengthometer->validate();
		}
		catch(PasswordTooShortException $exception) {
			$this->flash->error("Your password is too short, please pick a stronger one with at least " . Strengthometer::MIN_LENGTH . " characters");
			$this->reload();
		}

		$this->login(
			LoginData::TYPE_PASSWORD,
			$password
		);
	}

	public function doEmail(InputData $data):void {
		$this->login(
			LoginData::TYPE_EMAIL
		);
	}

	public function doSocialGoogle():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_GOOGLE
		);
	}

	public function doSocialTwitter():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_TWITTER
		);
	}

	public function doSocialFacebook():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_FACEBOOK
		);
	}

	public function doSocialLinkedIn():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_LINKEDIN
		);
	}

	public function doSocialGithub():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_GITHUB
		);
	}

	public function doSocialMicrosoft():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_MICROSOFT
		);
	}

	private function login(string $type, string $data = null):void {
		$this->restoreLoginData();

		$user = $this->userRepo->getOrCreate(
			$this->loginData,
			$this->deployment
		);
		$this->userRepo->save($user);

		try {
			$this->userRepo->handleLogin(
				$user,
				$type,
				$data
			);
		}
		catch(LoginNeedsEmailConfirmationException $exception) {
			$code = new ConfirmationCode();
			$this->userRepo->storeConfirmationCode(
				$code,
				$exception->getId()
			);

// TODO: Implement email queue once Cron can be executed back-to-back
// (see https://github.com/PhpGt/Cron/issues/11)
			$emailQueue = new ConfirmationEmailQueue(
				$this->database->queryCollection("email")
			);
			$emailQueue->setClientName(
				$this->deployment->getApplication()->getDisplayName()
			);
			$emailQueue->setCode($code->getFormatted());
			$emailQueue->setUser($user);
			$emailQueue->addToQueue();

			$email = new Mail();
			$email->setFrom("confirmation@authwave.com");
			$email->setSubject($emailQueue->getSubject());
			$email->addTo($user->getEmail());
			$email->addContent("text/plain", $emailQueue->getBodyText());

			try {
				$sendgrid = new \SendGrid($this->config->get("sendgrid.api_key"));
				$sendgrid->send($email);
			}
			catch(\Exception $exception) {
				$this->flash->error("Error sending confirmation code");
				$this->reload();
			}

			$this->redirect("/login/confirm");
		}

		$authUri = AuthUriFactory::buildAuthUri(
			$this->requestData,
			$this->deployment,
			$user
		);
		$this->redirect(
			"/login/success?continue="
			. $authUri->encode()
		);
	}

	private function outputProviders(Element $outputTo):void {
		$providers = [
//			"Google",
//			"Facebook",
//			"Twitter",
//			"LinkedIn",
//			"Github",
//			"Microsoft",
		];

		$outputTo->bindList($providers);

		if(empty($providers)) {
			$outputTo->closest(".auth-option")->remove();
		}
	}

	private function restoreLoginData():void {
		try {
			$this->loginData = $this->session->get(
				LoginData::SESSION_LOGIN_DATA
			);
		}
		catch(TypeError $error) {
			$this->redirect("/login");
		}
	}

	private function outputEmailAddress():void {
		/** @var LoginData $loginData */
		$loginData = $this->session->get(LoginData::SESSION_LOGIN_DATA);
		$this->document->bindKeyValue("email", $loginData->getEmail());
	}
}