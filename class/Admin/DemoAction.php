<?php
namespace Authwave\Admin;

use Authwave\Session\LoginSession;
use Authwave\User\{LoginState, UserRepository};
use Authwave\Security\{AdminAccess, AdminAccessDenied};
use Gt\Http\Response;

/** Authorised demo POST handling for both pages and components. */
class DemoAction {
	public static function submit(
		\Gt\Http\Request $request,
		\Gt\Input\Input $input,
		LoginSession $loginSession,
		UserRepository $userRepo,
		AdminAccess $adminAccess,
		\Authwave\Admin\DemoWorkspace $workspace,
		Response $response,
	):void {
		// POST actions run before go(), so authorise here as well as on every view.
		$path = rtrim($request->getUri()->getPath(), "/");
		$pages = ["", "organisation", "security", "emails", "applications", "customisation", "users", "integrations", "billing"];
		if($request->getMethod() !== "POST" || !in_array($path, array_map(static fn($page) => "/admin" . ($page ? "/$page" : ""), $pages), true)) return;
		if($loginSession->getState() !== LoginState::LOGGED_IN
		|| !$adminAccess->allows($userRepo->get($loginSession->getDeployment(), $loginSession->getEmail()))) {
			throw new AdminAccessDenied("Administrator access is required.");
		}
		try { $workspace->mutate($input); }
		catch(\InvalidArgumentException $error) { $workspace->setNotice($error->getMessage()); }
		$page = trim(substr($request->getUri()->getPath(), strlen("/admin")), "/");
		$query = [];
		foreach(["template", "sender"] as $key) if($value = $input->getString($key)) $query[$key] = $value;
		if($workspace->changedSender) $query["sender"] = $workspace->changedSender;
		$response->redirect($workspace->url($page, $query));
	}
}
