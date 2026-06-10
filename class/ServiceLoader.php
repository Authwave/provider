<?php
namespace Authwave;

use Authwave\Email\EmailRepository;
use Authwave\Model\EmailSettings;
use Authwave\Session\FlashSession;
use Authwave\Session\LoginSession;
use Authwave\Model\ApplicationRepository;
use Authwave\User\UserRepository;
use Gt\Database\Database;
use Gt\Session\Session;
use Gt\WebEngine\Middleware\DefaultServiceLoader;

class ServiceLoader extends DefaultServiceLoader {
	public function loadFlashSession():FlashSession {
		return new FlashSession(
			$this->container->get(Session::class)
				->getStore(FlashSession::SESSION_KEY, true)
		);
	}

	public function loadLoginSession():LoginSession {
		$session = $this->container->get(Session::class);
		$sessionStore = $session->getStore(LoginSession::SESSION_STORE_KEY, true);
		return new LoginSession($sessionStore);
	}

	public function loadSiteRepo():ApplicationRepository {
		return new ApplicationRepository(
			$this->container->get(Database::class)->queryCollection("application")
		);
	}

	public function loadUserRepo():UserRepository {
		return new UserRepository(
			$this->container->get(Database::class)->queryCollection("user"),
			$this->container->get(ApplicationRepository::class),
			$this->container->get(EmailRepository::class),
		);
	}

	public function loadEmailRepo():EmailRepository {
		$defaultEmailSettings = new EmailSettings(
			$this->config->getString("email.host"),
			$this->config->getInt("email.port"),
			$this->config->getString("email.username"),
			$this->config->getString("email.password"),
		);

		return new EmailRepository(
			$this->container->get(Database::class)->queryCollection("email"),
			$defaultEmailSettings,
		);
	}
}
