<?php
namespace Authwave\Test\Behat;

/** A pseudo-TTY may belong to an IDE output console without cursor support. */
class TerminalCapabilities {
	public static function supportsCursorMovement():bool {
		if(!stream_isatty(STDIN) || !stream_isatty(STDOUT) || getenv("TERM") === "dumb") {
			return false;
		}
		$terminal = @fopen("/dev/tty", "r+");
		if($terminal === false) {
			return false;
		}
		$settings = null;
		try {
			$settings = self::stty($terminal, ["-g"]);
			if($settings === null || self::stty($terminal, ["-icanon", "-echo", "min", "0", "time", "0"]) === null) {
				return false;
			}
			return self::verifyMovement(
				fn():?array => self::position($terminal, STDOUT),
				fn(string $sequence) => fwrite(STDOUT, $sequence),
			);
		}
		finally {
			if($settings !== null) {
				self::stty($terminal, [$settings]);
			}
			fclose($terminal);
		}
	}

	/** A position response alone does not prove that cursor-up is supported. */
	public static function verifyMovement(callable $position, callable $write):bool {
		$before = $position();
		if($before === null) {
			return false;
		}
		try {
			// At the top row, move down instead to avoid a clamped cursor-up.
			$delta = $before[0] > 1 ? -1 : 1;
			$write($delta === -1 ? "\033[1A" : "\033[1B");
			$after = $position();
			return $after === [$before[0] + $delta, $before[1]];
		}
		finally {
			$write("\033[{$before[0]};{$before[1]}H");
		}
	}

	public static function query($input, $output):bool {
		return self::position($input, $output) !== null;
	}

	private static function position($input, $output):?array {
		fwrite($output, "\033[6n");
		fflush($output);
		$blocking = stream_get_meta_data($input)["blocked"];
		stream_set_blocking($input, false);
		try {
			$deadline = microtime(true) + 0.15;
			$response = "";
			do {
				$read = [$input];
				$write = $except = [];
				$remaining = max(0, (int)(($deadline - microtime(true)) * 1_000_000));
				if(stream_select($read, $write, $except, 0, $remaining) !== 1) {
					return null;
				}
				$characters = fread($input, 32);
				if($characters === "" || $characters === false) {
					return null;
				}
				$response .= $characters;
				if(preg_match('/\A\x1b\[([0-9]+);([0-9]+)R\z/', $response, $match)) {
					return [(int)$match[1], (int)$match[2]];
				}
			} while(microtime(true) < $deadline && strlen($response) < 32);
			return null;
		}
		finally {
			stream_set_blocking($input, $blocking);
		}
	}

	private static function stty($terminal, array $arguments):?string {
		$process = @proc_open(["stty", ...$arguments], [0 => $terminal, 1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]], $pipes);
		if(!is_resource($process)) {
			return null;
		}
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return proc_close($process) === 0 ? trim($output) : null;
	}
}
