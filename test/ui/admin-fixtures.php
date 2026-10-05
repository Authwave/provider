<?php
// CLI fixture only: templates and sample data, without application services.
if(PHP_SAPI !== "cli") { exit(1); }
require dirname(__DIR__, 2) . "/vendor/autoload.php";
require __DIR__ . "/View.php";
chdir(dirname(__DIR__, 2));
$fixture = new class("fixture") extends PHPUnit\Framework\TestCase {
	public function render(array $query):string {
		$login = new Authwave\Session\LoginSession(new Gt\Session\SessionStore("preview", $this->createMock(Gt\Session\Session::class)), $this->createMock(Authwave\Security\Audit::class), $this->createMock(Authwave\Security\AnonUser::class));
		$application = new Authwave\Model\Application("admin-preview-no-logo", "TrackShift", "sender@example.test", themes: [
			new Authwave\Model\ApplicationTheme("light", ["primary" => "#272635", "secondary" => "#6ab3df", "pageBackground" => "#fcf0f5", "bodyText" => "#453c54", "headingText" => "#453c54", "panelBorder" => "#aca9c7", "buttonPrimaryText" => "#ffffff"]),
			new Authwave\Model\ApplicationTheme("dark", ["primary" => "#6ab3df", "secondary" => "#aca9c7", "pageBackground" => "#272635", "panelBackground" => "#252734", "panelBorder" => "#4f4c6b", "bodyText" => "#f2f2f2", "headingText" => "#fcf0f5", "buttonPrimaryText" => "#272635"]),
		]);
		$login->setDeploymentForLogin(new Authwave\Model\ApplicationDeployment("preview", $application, "TrackShift", "secret", "client.example.test", "/"));
		$login->setEmail("olivia@example.test");
		$view = new Authwave\Test\UI\View("admin/index");
		(new Authwave\View\AdminDashboard())->apply($view->document, $view->binder, $login, new Gt\Input\Input($query));
		return (string)$view->document;
	}
};
file_put_contents($argv[1], $fixture->render(json_decode($argv[2] ?? "{}", true, flags: JSON_THROW_ON_ERROR)));
