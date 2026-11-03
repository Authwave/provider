<?php

namespace Authwave\View;

use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationLogo;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;
readonly class LoginBranding {

	public function apply(
		HTMLDocument $document,
		Binder $binder,
		ApplicationDeployment $deployment
	): void {
		$application = $deployment->application;
		$this->bindIdentity($binder, $deployment);
		$binder->bindKeyValue("title", "{$deployment->title} - Login");
		$css = [];
		foreach ($application->getThemes() as $theme) {
			if ($rule = $theme->toCss()) {
				$css[] = $rule;
			}
		}
		if ($css) {
			$style = $document->createElement("style");
			$style->id = "application-theme";
			$style->textContent = implode("\n", $css);
			$document->head->appendChild($style);
		}
	}
	/** Uses stateless utility/framework APIs.
	* @SuppressWarnings("PHPMD.StaticAccess")
	*/
	public function bindIdentity(Binder $binder, ApplicationDeployment $deployment): void {
		$logoPath = ApplicationLogo::getPath($deployment->application) ?? "/asset/default-logo.svg";
		$darkLogoPath = ApplicationLogo::getPath($deployment->application, "logo_dark") ?? $logoPath;
		$binder->bindKeyValue("applicationName", $deployment->title);
		$binder->bindKeyValue("logoPath", $logoPath);
		$binder->bindKeyValue("darkLogoPath", $darkLogoPath);
	}
}
