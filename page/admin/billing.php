<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(HTMLDocument $document, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($document, $binder, $input, $workspace, $uri);
	$view->bindSettings($workspace->settings());
	$plan = $workspace->settings()["plan"];
	[$limit, $price] = ["Starter" => [1000, 0], "Growth" => [10000, 29], "Scale" => [50000, 99]][$plan];
	$usage = count($workspace->applications()) * 2431;
	$percent = min(100, (int)round($usage / $limit * 100));
	$view->keys(["currentPlan" => $plan, "nextPayment" => "Next payment: " . (new \DateTimeImmutable("first day of next month"))->format("j F Y"), "planPrice" => "£$price per month (demo)", "usagePercent" => $percent, "usageLabel" => number_format($usage) . " / " . number_format($limit) . " monthly users · $percent%"]);
	$view->forms();
}
