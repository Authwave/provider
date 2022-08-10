<?php
namespace Authwave;

use Authwave\Session\FlashSession;
use Authwave\Session\LoginSession;
use Authwave\Site\SiteRepository;
use Authwave\User\User;
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
	public function loadLoginSession():?LoginSession {
		$session = $this->container->get(Session::class);
		if($sessionStore = $session->getStore(LoginSession::SESSION_STORE_KEY)) {
			return new LoginSession($sessionStore);
		}

		return null;
	}

	#[LazyLoad]
	public function loadSiteRepo():SiteRepository {
		return new SiteRepository(
			$this->container->get(Database::class)->queryCollection("site")
		);
	}

	#[LazyLoad]
	public function loadUserRepo():UserRepository {
		return new UserRepository(
			$this->container->get(Database::class)->queryCollection("user")
		);
	}
}
