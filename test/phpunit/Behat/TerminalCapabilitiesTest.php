<?php
namespace Authwave\Test\Behat;

use PHPUnit\Framework\TestCase;

class TerminalCapabilitiesTest extends TestCase {
	public function testMovement_rejectsAConsoleThatReportsPositionButIgnoresMovement():void {
		$output = "";
		self::assertFalse(TerminalCapabilities::verifyMovement(
			fn():array => [12, 40],
			function(string $sequence)use(&$output):void { $output .= $sequence; },
		));
		self::assertSame("\033[1A\033[12;40H", $output);
	}

	public function testMovement_acceptsMovementAndRestoresTheOriginalPosition():void {
		$positions = [[12, 40], [11, 40]];
		$output = "";
		self::assertTrue(TerminalCapabilities::verifyMovement(
			function()use(&$positions):array { return array_shift($positions); },
			function(string $sequence)use(&$output):void { $output .= $sequence; },
		));
		self::assertSame("\033[1A\033[12;40H", $output);
	}

	public function testComposer_withoutTerminalSupportSelectsSingleLineInsteadOfWorkerRows():void {
		$previousServer = $_SERVER;
		$previousEnv = $_ENV;
		$previousMode = getenv("BEHAT_PROGRESS_MODE");
		$previousInteractive = getenv("BEHAT_INTERACTIVE");
		$previousTerm = getenv("TERM");
		putenv("BEHAT_PROGRESS_MODE");
		putenv("BEHAT_INTERACTIVE");
		putenv("TERM=dumb");
		try {
			ComposerProgress::configure();
			self::assertSame("single-line", getenv("BEHAT_PROGRESS_MODE"));
			self::assertSame("0", getenv("BEHAT_INTERACTIVE"));
			self::assertSame("single-line", $_SERVER["BEHAT_PROGRESS_MODE"]);
			self::assertSame("single-line", $_ENV["BEHAT_PROGRESS_MODE"]);
		}
		finally {
			$_SERVER = $previousServer;
			$_ENV = $previousEnv;
			foreach(["BEHAT_PROGRESS_MODE" => $previousMode, "BEHAT_INTERACTIVE" => $previousInteractive, "TERM" => $previousTerm] as $name => $value) {
				putenv($value === false ? $name : "$name=$value");
			}
		}
	}

	public function testQuery_acceptsACursorPositionReport():void {
		[$input, $terminal] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$output = tmpfile();
		try {
			fwrite($terminal, "\033[12;40R");
			self::assertTrue(TerminalCapabilities::query($input, $output));
			rewind($output);
			self::assertSame("\033[6n", stream_get_contents($output));
		}
		finally {
			fclose($input);
			fclose($terminal);
			fclose($output);
		}
	}

	public function testQuery_timesOutWhenTheConsoleDoesNotRespond():void {
		[$input, $terminal] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$output = tmpfile();
		try {
			self::assertFalse(TerminalCapabilities::query($input, $output));
		}
		finally {
			fclose($input);
			fclose($terminal);
			fclose($output);
		}
	}
}
