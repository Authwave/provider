<?php
namespace Authwave\Dns;

use Closure;
use RuntimeException;
use GT\Logger\Log;

readonly class Nsd {
	/** @var Closure(list<string>):array{int, string} */
	private Closure $execute;

	/** @param null|Closure(list<string>):array{int, string} $execute */
	public function __construct(private string $directory, ?Closure $execute = null) {
		$this->execute = $execute ?? $this->execute(...);
	}

	public function add(string $host):void {
		$this->update($host, true);
	}

	public function remove(string $host):void {
		$this->update($host, false);
	}

	private function update(string $host, bool $present):void {
		$hostname = (new Hostname())->publicName($host);
		if($hostname === null) {
			return;
		}
		[$code, $containers] = ($this->execute)([
			dirname($this->directory, 2) . "/bin/authwave-dns", "status",
		]);
		if($this->logSkipped($code, $containers)) {
			return;
		}
		if($code !== 0) {
			throw new RuntimeException("DNS helper failed: " . trim($containers));
		}
		if(trim($containers) === "") {
			return;
		}
		$this->apply($hostname, $present);
	}

	private function apply(string $hostname, bool $present):void {
		if((new Hostname())->publicName($hostname) !== $hostname
		|| in_array($hostname, ["authwave.com", "ns1.authwave.com", "ns2.authwave.com"])) {
			throw new RuntimeException("Refusing to provision an invalid or reserved DNS zone.");
		}
		$arguments = [$present ? "addzone" : "delzone", $hostname];
		[$code, $output] = ($this->execute)([
			dirname($this->directory, 2) . "/bin/authwave-dns",
			...$arguments,
		]);
		if($this->logSkipped($code, $output)) {
			return;
		}
		if(!$present && trim($output) === "warning zone $hostname not present") {
			return;
		}
		if($code !== 0) {
			throw new RuntimeException("NSD command failed: " . trim($output));
		}
		if($present && str_contains($output, "already exists")) {
			[$code, $status] = ($this->execute)([
				dirname($this->directory, 2) . "/bin/authwave-dns",
				"zonestatus", $hostname,
			]);
			if($this->logSkipped($code, $status)) {
				return;
			}
			if($code !== 0 || !preg_match('/pattern:\s*authwave\s*$/m', $status)) {
				throw new RuntimeException("Existing zone does not use the authwave pattern.");
			}
		}
	}

	/** Exit status 3 means the helper could not find a running DNS service.
	 * @SuppressWarnings("PHPMD.StaticAccess")
	 */
	private function logSkipped(int $code, string $output):bool {
		if($code !== 3) {
			return false;
		}
		Log::warning(trim($output));
		return true;
	}

	/** @param list<string> $command
	 * @return array{int, string}
	 */
	private function execute(array $command):array {
		$output = tmpfile();
		if($output === false) {
			throw new RuntimeException("Could not allocate NSD command output.");
		}
		try {
			$process = proc_open($command, [
				0 => ["file", "/dev/null", "r"], 1 => $output, 2 => $output,
			], $pipes);
			unset($pipes);
			if(!is_resource($process)) {
				throw new RuntimeException("Could not start DNS helper.");
			}
			$deadline = microtime(true) + 15;
			do {
				$status = proc_get_status($process);
				if(!$status["running"]) {
					break;
				}
				usleep(50000);
			} while(microtime(true) < $deadline);
			if($status["running"]) {
				proc_terminate($process, 9);
			}
			proc_close($process);
			rewind($output);
			return [$status["running"] ? 124 : $status["exitcode"], stream_get_contents($output)];
		}
		finally {
			fclose($output);
		}
	}
}
