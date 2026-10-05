<?php
namespace Authwave\View;

use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationLogo;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;

class LoginBranding {
	public function apply(HTMLDocument $document, Binder $binder, ApplicationDeployment $deployment):void {
		$application = $deployment->application;
		$logoPath = ApplicationLogo::getPath($application) ?? "/asset/default-logo.svg";
		$darkLogoPath = ApplicationLogo::getPath($application, "logo_dark") ?? $logoPath;

		$binder->bindKeyValue("title", "$deployment->title - Login");
		$binder->bindKeyValue("applicationName", $deployment->title);

		$binder->bindKeyValue("logoPath", $logoPath);
		$binder->bindKeyValue("darkLogoPath", $darkLogoPath);

		$css = [];
		foreach($application->themes ?? [] as $theme) {
			if($rule = $theme->toCss()) {
				$css []= $rule;
			}
		}
		if($css) {
			$style = $document->createElement("style");
			$style->id = "application-theme";
			$style->textContent = implode("\n", $css);
			$document->head->appendChild($style);
		}
	}

}
