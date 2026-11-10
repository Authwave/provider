<?php
namespace Authwave;

use Authwave\Model\{Application, ApplicationRepository};
use Authwave\Notification\UserNotifications;
use GT\Fetch\Http;
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Application\ApplicationSettingsAction;
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
use Authwave\Security\Sudo;
use Authwave\Security\DomainVerification;
use Authwave\User\UserRepository;
use GT\Database\Database;
use GT\Input\Input;
use GT\Cookie\CookieHandler;
use Psr\Http\Message\ServerRequestInterface;
use GT\Session\Session;
use GT\WebEngine\Service\DefaultServiceLoader;

class ServiceLoader extends DefaultServiceLoader {
	public function loadHttp():Http {
		return new Http([
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => $this->config->getBool("app.production")
				? CURLPROTO_HTTPS : CURLPROTO_HTTP | CURLPROTO_HTTPS,
		]);
	}

	public function loadUserNotifications():UserNotifications {
		return new UserNotifications($this->container->get(Database::class),
			$this->container->get(Http::class), $this->container->get(Audit::class),
			$this->container->get(ApplicationRepository::class),
			$this->container->get(UserRepository::class));
	}

	public function loadDomainVerification():DomainVerification {
		return new DomainVerification($this->config->getString("authwave.domain_confirmation")
			?? DomainVerification::DEFAULT_CONFIRMATION);
	}

	public function loadSudo():Sudo {
		return new Sudo(
			$this->container->get(Session::class)->getStore(LoginSession::SESSION_STORE_KEY . ".sudo", true),
			$this->container->get(UserRepository::class),
			$this->container->get(Audit::class),
			$this->config->getInt("authwave.sudo_duration") ?? 60,
		);
	}

	public function loadApplication():Application {
		return $this->container->get(ApplicationRepository::class)->get(
			$this->container->get(AdminArea::class)->administrator(),
			$this->container->get(DynamicPath::class)->get("applicationId") ?? "",
		);
	}

	public function loadApplicationSettingsAction():ApplicationSettingsAction {
		return new ApplicationSettingsAction($this->container->get(ApplicationRepository::class),
			$this->container->get(Application::class),
			$this->container->get(AdminArea::class)->administrator(),
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
			$this->container->get(Audit::class), $this->container->get(DomainVerification::class));
	}

	public function loadCookieHandler():CookieHandler {
		$request = $this->container->get(ServerRequestInterface::class);
		return new CookieHandler($request->getCookieParams());
	}

	public function loadUserRepo():UserRepository {
		return new UserRepository(
			$this->container->get(Database::class),
			$this->container->get(ApplicationRepository::class),
			$this->container->get(EmailRepository::class),
			$this->container->get(Audit::class),
			$this->config->getInt("authwave.client_session_duration")
				?? UserRepository::DEFAULT_SESSION_DURATION,
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
