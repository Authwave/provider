<?php
namespace Authwave;

use Authwave\Email\EmailRepository;
use Authwave\Session\FlashSession;
use Authwave\Session\LoginSession;
use Authwave\Model\ApplicationRepository;
use Authwave\User\UserRepository;
use Gt\Database\Database;
use Gt\ServiceContainer\LazyLoad;
use Gt\Session\Session;
use Gt\WebEngine\Middleware\DefaultServiceLoader;

class ServiceLoader extends DefaultServiceLoader {
	#[LazyLoad]
	public function loadFlashSession():FlashSession {
		return new FlashSession(
			$this->container->get(Session::class)
				->getStore(FlashSession::SESSION_KEY, true)
		);
	}

	#[LazyLoad]
	public function loadLoginSession():LoginSession {
		$session = $this->container->get(Session::class);
		$sessionStore = $session->getStore(LoginSession::SESSION_STORE_KEY, true);
		return new LoginSession($sessionStore);
	}

	#[LazyLoad]
	public function loadSiteRepo():ApplicationRepository {
		return new ApplicationRepository(
			$this->container->get(Database::class)->queryCollection("application")
		);
	}

	#[LazyLoad]
	public function loadUserRepo():UserRepository {
		return new UserRepository(
			$this->container->get(Database::class)->queryCollection("user"),
			$this->container->get(ApplicationRepository::class),
			$this->container->get(EmailRepository::class),
		);
	}

	#[LazyLoad]
	public function loadEmailRepo():EmailRepository {
		return new EmailRepository(
			$this->container->get(Database::class)->queryCollection("email"),
			$this->config->getString("brevo.api_key"),
		);
	}
}
