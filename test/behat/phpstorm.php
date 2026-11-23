<?php
// Use as auto_prepend_file in PhpStorm's Behat run-configuration template.
// PhpStorm runs its own PHP launcher; rerun that launcher inside our test harness.
if(PHP_SAPI !== "cli" || (!isset($_SERVER["IDE_BEHAT_DIR"]) && !isset($_SERVER["IDE_BEHAT_PHAR"]))) {
	return;
}
$entrypoint = realpath($_SERVER["argv"][0] ?? "");
if($entrypoint === false) {
	throw new RuntimeException("Cannot locate PhpStorm's Behat launcher.");
}
putenv("BEHAT_BIN=" . $entrypoint);
putenv("BEHAT_PHP_BINARY=" . PHP_BINARY);
$command = [__DIR__ . "/run-behat", ...array_slice($_SERVER["argv"], 1)];
passthru(implode(" ", array_map("escapeshellarg", $command)), $status);
exit($status);
