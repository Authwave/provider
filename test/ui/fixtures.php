<?php
// CLI only: static fixtures using real templates and binders, with no app services.
if(PHP_SAPI !== "cli") { exit(1); }
require dirname(__DIR__, 2) . "/vendor/autoload.php";
require __DIR__ . "/View.php";
chdir(dirname(__DIR__, 2));
foreach(["index", "authenticate", "security-check", "success"] as $page) {
	$view = new Authwave\Test\UI\View("login/$page");
	foreach([
		"title" => "Test application - Login",
		"applicationName" => "Test application",
		"email" => "test@example.test",
		"returnUri" => "https://client.example.test/callback",
	] as $key => $value) {
		$view->binder->bindKeyValue($key, $value);
	}
	file_put_contents("$argv[1]/$page.html", (string)$view->document);
}
