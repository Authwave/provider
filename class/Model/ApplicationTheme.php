<?php
namespace Authwave\Model;

use InvalidArgumentException;

class ApplicationTheme {
	public const COLOUR_PROPERTIES = [
		"primary" => "--theme-color-primary",
		"secondary" => "--theme-color-secondary",
		"codeInputBorder" => "--theme-code-input-border",
		"positive" => "--theme-color-success",
		"negative" => "--theme-color-danger",
		"warning" => "--theme-color-warning",
		"interactionShade" => "--theme-interaction-shade",
		"focusOutline" => "--theme-focus-color",
		"pageBackground" => "--theme-color-page",
		"panelBackground" => "--theme-color-surface",
		"panelBorder" => "--theme-color-border",
		"controlBorder" => "--theme-control-border",
		"controlBackground" => "--theme-control-background",
		"controlBorderHover" => "--theme-control-border-hover",
		"controlBackgroundActive" => "--theme-control-background-active",
		"controlHighlight" => "--theme-control-highlight",
		"controlShade" => "--theme-control-shade",
		"controlHighlightHover" => "--theme-control-highlight-hover",
		"controlShadeHover" => "--theme-control-shade-hover",
		"buttonBackground" => "--theme-button-background",
		"buttonText" => "--theme-button-text",
		"buttonPrimaryText" => "--theme-button-primary-text",
		"buttonPrimaryBackground" => "--theme-button-primary-background",
		"buttonBorder" => "--theme-button-border",
		"buttonPrimaryBorder" => "--theme-button-primary-border",
		"buttonBorderHover" => "--theme-button-border-hover",
		"buttonBackgroundActive" => "--theme-button-background-active",
		"buttonPrimaryBorderHover" => "--theme-button-primary-border-hover",
		"buttonPrimaryBackgroundActive" => "--theme-button-primary-background-active",
		"bodyText" => "--theme-color-text",
		"controlPlaceholder" => "--theme-color-muted",
		"headingText" => "--theme-color-heading",
		"linkText" => "--theme-link-color",
		"linkTextHover" => "--theme-link-hover-color",
		"linkTextActive" => "--theme-link-active-color",
		"linkBackgroundActive" => "--theme-link-active-background",
	];

	/** @param array<string, mixed> $colours */
	public function __construct(
		public readonly string $colourScheme,
		public readonly array $colours,
	) {
		if(!in_array($colourScheme, ["light", "dark"], true)) {
			throw new InvalidArgumentException("Unknown colour scheme: $colourScheme");
		}
	}

	public static function fromJson(string $colourScheme, string $json):self {
		$colours = json_decode($json, true);
		return new self($colourScheme, is_array($colours) ? $colours : []);
	}

	public function toCss():string {
		$declarations = [];
		foreach(self::COLOUR_PROPERTIES as $key => $property) {
			$colour = $this->colours[$key] ?? null;
			// Whitelist both keys and values before embedding database content in CSS.
			if(is_string($colour) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $colour)) {
				$declarations []= "$property: $colour;";
			}
		}
		if(!$declarations) {
			return "";
		}

		return ":root[data-flair-theme=\"bright\"][data-color-scheme=\"$this->colourScheme\"] { "
			. implode(" ", $declarations) . " }";
	}
}
