<?php
use Gt\DomTemplate\Binder;

function go(Binder $binder):void {
	$binder->bindKeyValue("title", "Logged out - Authwave");
}
