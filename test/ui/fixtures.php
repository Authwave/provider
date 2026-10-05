<?php
// CLI only: static fixtures using real templates and binders, with no app services.
if(PHP_SAPI !== "cli") { exit(1); }
require dirname(__DIR__, 2) . "/vendor/autoload.php";
require __DIR__ . "/View.php";
chdir(dirname(__DIR__, 2));
foreach(["index", "authenticate", "security-check", "success"] as $page) {
	$view = new Authwave\Test\UI\View("login/$page");
	$style = $view->document->createElement("style");
	$style->textContent = (new Authwave\Model\ApplicationTheme("light", [
		"primary" => "#123456", "pageBackground" => "#f0f1f2", "buttonPrimaryText" => "#fff",
	]))->toCss() . "\n" . (new Authwave\Model\ApplicationTheme("dark", [
		"primary" => "#abcdef", "pageBackground" => "#101112", "buttonPrimaryText" => "#000",
	]))->toCss();
	$view->document->head->appendChild($style);
	foreach([
		"title" => "Test application - Login",
		"applicationName" => "Test application",
		"logoPath" => "/asset/default-logo.svg?light",
		"darkLogoPath" => "/asset/default-logo.svg?dark",
		"email" => "test@example.test",
		"returnUri" => "https://client.example.test/callback",
	] as $key => $value) {
		$view->binder->bindKeyValue($key, $value);
	}
	file_put_contents("$argv[1]/$page.html", (string)$view->document);
}
