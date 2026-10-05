<?php
namespace Authwave\Email;

use League\CommonMark\CommonMarkConverter;
use Symfony\Component\Mime\HtmlToTextConverter\DefaultHtmlToTextConverter;
use UnexpectedValueException;

class EmailTemplate {
	public function __construct(private readonly string $directory = "data/email") {}

	/** @return array{subject: string, html: string, text: string} */
	public function render(string $name, array $values = []):array {
		$paths = pathinfo($name, PATHINFO_EXTENSION)
			? ["$this->directory/$name"]
			: ["$this->directory/$name.html", "$this->directory/$name.md"];
		$path = null;
		foreach($paths as $candidate) {
			if(is_file($candidate)) {
				$path = $candidate;
				break;
			}
		}
		if($path === null) {
			throw new EmailTemplateNotFoundException($name);
		}
		$template = trim(file_get_contents($path));
		switch(strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
		case "html":
			if(!preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $template, $matches)) {
				throw new UnexpectedValueException("HTML email template requires a title: $name");
			}
			$subject = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, "UTF-8");
			$html = $template;
			break;
		case "md":
			[$subject, $body] = array_pad(preg_split('/\R/', $template, 2), 2, "");
			$subject = ltrim($subject, "# \t");
			$html = (string)(new CommonMarkConverter())->convert(ltrim($body));
			break;
		default:
			throw new UnexpectedValueException("Unsupported email template extension: $name");
		}

		$plainValues = $htmlValues = [];
		foreach($values as $key => $value) {
			if(is_scalar($value)) {
				$placeholder = "{{" . $key . "}}";
				$plainValues[$placeholder] = (string)$value;
				$htmlValues[$placeholder] = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
			}
		}
		$subject = trim(strtr($subject, $plainValues));
		$html = strtr($html, $htmlValues);
		// Retain paragraph boundaries, but omit head/style content from text MIME.
		$textHtml = preg_replace('/<br\s*\/?\s*>|<\/(?:p|h[1-6]|tr|div)>/i', "$0\n", $html);
		$text = (new DefaultHtmlToTextConverter())->convert($textHtml, "UTF-8");
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, "UTF-8");
		$text = preg_replace('/^[\t ]+|[\t ]+$/m', '', $text);
		$text = trim(preg_replace('/\n{3,}/', "\n\n", $text));
		return ["subject" => $subject, "html" => $html, "text" => $text];
	}
}
