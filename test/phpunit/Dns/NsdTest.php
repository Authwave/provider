<?php
namespace Authwave\Test\Dns;

use Authwave\Dns\{Hostname, Nsd};
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NsdTest extends TestCase {
	public function testSkipsLocalAndUnsafeHostnames():void {
		foreach(["localhost:9105", "127.0.0.1", "192.168.1.1", "login.test", "login.local",
			"-option.example.com", "https://example.com", "*.example.com", "ns1.authwave.com"] as $host) {
			self::assertNull((new Hostname())->publicName($host));
		}
		self::assertSame("login.example.com", (new Hostname())->publicName("LOGIN.example.com.:443"));
	}

	public function testUnavailableDockerSkipsProvisioning():void {
		$calls = [];
		$nsd = new Nsd("/nsd", function(array $command)use(&$calls):array {
			$calls []= $command;
			return [3, "Docker is not running or is unavailable; DNS provisioning skipped."];
		});
		$nsd->add("login.example.com");
		self::assertCount(1, $calls);
		self::assertContains("status", $calls[0]);
	}

	public function testStoppedServiceSkipsProvisioning():void {
		$calls = 0;
		$nsd = new Nsd("/nsd", function(array $command)use(&$calls):array {
			$calls++;
			return [3, "NSD container is not running; DNS provisioning skipped."];
		});
		$nsd->remove("login.example.com");
		self::assertSame(1, $calls);
	}

	public function testMissingZoneDeletionIsIdempotent():void {
		$nsd = new Nsd("/nsd", fn(array $command):array => in_array("status", $command) ? [0, "container-id"] : [1,
			"warning zone " . end($command) . " not present\n"]);
		$nsd->remove("login.example.com");
		self::assertTrue(true);
	}

	public function testExistingZoneMustUseExpectedPattern():void {
		$nsd = new Nsd("/nsd", fn(array $command):array => in_array("status", $command) ? [0, "container-id"]
			: (in_array("addzone", $command)
			? [0, "zone login.example.com already exists\n"] : [0, "pattern: other\n"]));
		$this->expectException(RuntimeException::class);
		$nsd->add("login.example.com");
	}

	public function testExistingZoneWithExpectedPatternIsAccepted():void {
		$nsd = new Nsd("/nsd", fn(array $command):array => in_array("status", $command) ? [0, "container-id"]
			: (in_array("addzone", $command)
			? [0, "zone login.example.com already exists\n"] : [0, "\tpattern: authwave\n"]));
		$nsd->add("login.example.com");
		self::assertTrue(true);
	}
}
