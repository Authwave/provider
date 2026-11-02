<?php

namespace Authwave\Admin;

use Authwave\Session\LoginSession;
use Authwave\Security\AdminAccessDenied;
/** Shared logout action for the sidebar component and the standalone page. */
class LogoutAction {

	public static function submit(
		\Gt\Http\Request $request,
		\Gt\Http\Response $response,
		\Gt\Session\Session $session,
		\Gt\Config\Config $config,
		LoginSession $loginSession,
		\Authwave\User\UserRepository $userRepo,
		\Authwave\Security\AdminAccess $adminAccess
	): void {
		if ($request->getMethod() !== "POST" || rtrim($request->getUri()->getPath(),
			"/") !== "/admin/logout") {
			return;
		}
		if ($loginSession->getState() !== \Authwave\User\LoginState::LOGGED_IN || !$adminAccess->allows(
			$userRepo->get($loginSession->getDeployment(), $loginSession->getEmail())
		)) {
			throw new AdminAccessDenied("Administrator access is required.");
		}
		$loginSession->clearDataForLogout($loginSession->getDeployment());
		$session->kill();
		$response->redirect($config->getString("authwave.logout_redirect") ?: "/logged-out/");
	}
}
