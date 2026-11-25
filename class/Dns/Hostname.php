<?php
namespace Authwave\Dns;

class Hostname {
	public function publicName(string $host):?string {
		$name = strtolower(rtrim(explode(":", $host, 2)[0], "."));
		if(in_array($name, ["authwave.com", "ns1.authwave.com", "ns2.authwave.com"])
		|| !str_contains($name, ".") || strlen($name) > 253
		|| filter_var($name, FILTER_VALIDATE_IP) !== false
		|| !filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
			return null;
		}
		foreach(["test", "localhost", "local", "invalid", "example"] as $suffix) {
			if(str_ends_with($name, ".$suffix")) {
				return null;
			}
		}
		return $name;
	}
}
