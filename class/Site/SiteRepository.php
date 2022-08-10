<?php
namespace Authwave\Site;

use Gt\Cipher\Key;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use Gt\Http\Uri;

class SiteRepository {
	public function __construct(
		private readonly QueryCollection $db
	) {
	}

	public function load(?string $hexUri):Site {
		if(!$hexUri) {
			throw new SiteNotFoundException($hexUri);
		}

		$uri = new Uri(hex2bin($hexUri));
		$siteHost = $uri->getHost();
		if($port = $uri->getPort()) {
			$siteHost .= ":$port";
		}

		$site = $this->rowToSite(
			$this->db->fetch("getByHost", $siteHost)
		);
		if(!$site) {
			throw new SiteNotFoundException($hexUri);
		}

		return $site;
	}

	private function rowToSite(?Row $row):?Site {
		if(!$row) {
			return null;
		}

		return new Site(
			$row->getString("id"),
			$row->getString("host"),
			new Uri($row->getString("uri")),
			new Key(base64_decode($row->getString("apiKey"))),
			$row->getString("name"),
		);
	}
}
