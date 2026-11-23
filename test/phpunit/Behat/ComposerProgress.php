<?php
namespace Authwave\Test\Behat;

/** Composer pipes child output, so capture terminal support before launching PHP. */
class ComposerProgress {
	public static function configure():void {
		$terminal = stream_isatty(STDOUT) && getenv("TERM") !== "dumb";
		if(getenv("BEHAT_PROGRESS_MODE") === false) {
			$multiline = $terminal && TerminalCapabilities::supportsCursorMovement();
			self::setEnvironment("BEHAT_PROGRESS_MODE", $multiline ? "multi-line" : "single-line");
		}
		if(getenv("BEHAT_INTERACTIVE") === false) {
			self::setEnvironment("BEHAT_INTERACTIVE", $terminal ? "1" : "0");
		}
	}

	private static function setEnvironment(string $name, string $value):void {
		// Symfony Process merges these arrays into child environments after getenv().
		putenv("$name=$value");
		$_SERVER[$name] = $_ENV[$name] = $value;
	}

}
