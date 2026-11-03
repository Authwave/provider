<?php
namespace Authwave;

use Authwave\Model\ApplicationRepository;
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Application\{ApplicationSettingsContext,
	ApplicationSettingsAction};
use GT\Routing\Path\DynamicPath;
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Admin\Onboarding\Onboarding;
use Authwave\Email\EmailRepository;
use Authwave\Model\EmailSettings;
use Authwave\Session\FlashSession;
use Authwave\Session\LoginSession;
use Authwave\Security\AnonUser;
use Authwave\Security\AdminAccess;
use Authwave\Security\Audit;
use Authwave\User\UserRepository;
use Gt\Database\Database;
use GT\Input\Input;
use Gt\Session\Session;
use GT\WebEngine\Service\DefaultServiceLoader;

class ServiceLoader extends DefaultServiceLoader {

	public function loadApplicationSettingsContext():ApplicationSettingsContext {
		$opened = $this->container->get(Input::class)->get("deployment", Input::DATA_QUERYSTRING);
		return new ApplicationSettingsContext($this->container->get(ApplicationRepository::class),
			$this->container->get(AdminArea::class)->administrator(),
			$this->container->get(DynamicPath::class)->get("applicationId") ?? "",
			is_string($opened) ? $opened : null);
	}

	public function loadApplicationSettingsAction():ApplicationSettingsAction {
		return new ApplicationSettingsAction($this->container->get(ApplicationRepository::class),
			$this->container->get(ApplicationSettingsContext::class),
			$this->container->get(FlashSession::class), $this->container->get(Audit::class));
	}

	public function loadApplicationCatalogue():ApplicationCatalogue {
		$input = $this->container->get(Input::class);
		$requested = $input->get("application", Input::DATA_QUERYSTRING);

		if($this->container->has(DynamicPath::class)) {
			$requested = $this->container->get(DynamicPath::class)->get("applicationId") ?? $requested;
		}

		return new ApplicationCatalogue(
			$this->container->get(ApplicationRepository::class),
			$this->container->get(AdminArea::class)->administrator(),
			$this->container->get(Session::class)->getStore("APPLICATION_SELECTION", true),
			$this->container->get(Audit::class),
			is_string($requested) ? $requested : null,
		);
	}

	public function loadAdminArea():AdminArea {
		return new AdminArea(
			$this->container->get(LoginSession::class),
			$this->container->get(UserRepository::class),
			$this->container->get(AdminAccess::class),
			$this->container->get(ApplicationRepository::class),
		);
	}

	public function loadOnboarding():Onboarding {
		return $this->container->get(AdminArea::class)->onboarding();
	}

	public function loadAdminAccess():AdminAccess {
		return new AdminAccess(
			$this->container->get(Database::class)->queryCollection("user_admin"),
			$this->config->getString("authwave.admin_email"),
		);
	}

	public function loadAnonUser():AnonUser {
		$sessionId = $this->container->get(Session::class)->getId();
		return new AnonUser($sessionId);
	}

	public function loadAudit():Audit {
		return new Audit(
			$this->container->get(Database::class)->queryCollection("audit")
		);
	}

	public function loadFlashSession():FlashSession {
		return new FlashSession(
			$this->container->get(Session::class)
				->getStore(FlashSession::SESSION_KEY, true)
		);
	}

	public function loadLoginSession():LoginSession {
		$session = $this->container->get(Session::class);
		$sessionStore = $session->getStore(LoginSession::SESSION_STORE_KEY, true);
		return new LoginSession(
			$sessionStore,
			$this->container->get(Audit::class),
			$this->container->get(AnonUser::class),
		);
	}

	public function loadSiteRepo():ApplicationRepository {
		return new ApplicationRepository($this->container->get(Database::class),
			$this->container->get(Audit::class));
	}

	public function loadUserRepo():UserRepository {
		return new UserRepository(
			$this->container->get(Database::class)->queryCollection("user"),
			$this->container->get(ApplicationRepository::class),
			$this->container->get(EmailRepository::class),
			$this->container->get(Audit::class),
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
			$this->container->get(Audit::class),
			ignoreTransportErrors: !$this->config->getBool("app.production"),
		);
	}
}
