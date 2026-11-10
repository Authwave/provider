<?php

use Authwave\Test\Behat\DatabaseState;
use Behat\Hook\AfterScenario;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Behat\Mink\Exception\ExpectationException;

require_once dirname(__DIR__) . "/database/DatabaseState.php";

class FeatureContext extends MinkContext {
	#[Given('the client responds to notifications with HTTP :status')]
	public function clientNotificationResponse(int $status):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$database->prepare("update ClientNotificationResponse set status = ?")->execute([$status]);
	}

	#[Then('the client should have received user :email :count time')]
	#[Then('the client should have received user :email :count times')]
	public function receivedClientNotifications(string $email, int $count):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select count(*), count(distinct iv) from ClientNotificationReceipt where email = ?");
		$query->execute([$email]);
		$row = $query->fetch(PDO::FETCH_NUM);
		$this->expect((int)$row[0] === $count && (int)$row[1] === $count,
			"Expected $count immediate notifications with fresh encryption for $email.");
	}

	#[When('I redeliver the client notification for :email')]
	public function redeliverClientNotification(string $email):void {
		$this->getSession()->resizeWindow(390, 1000);
		$this->openUserDetails($email);
		$form = $this->clientNotificationForm($email);
		$this->expect($this->getSession()->evaluateScript('(() => {
			const button = document.querySelector("#all-users .user-details:popover-open button[value=redeliver]");
			const range = document.createRange();
			range.selectNodeContents(button);
			return range.getClientRects().length === 1;
		})()'), "The Redeliver label must remain on one line on a narrow screen.");
		$form->pressButton("Redeliver");
		$this->expect($this->getSession()->wait(5000,
			'/Client notification delivered\.|User deleted\./.test(document.querySelector("#all-users [role=status]")?.textContent)'),
			"Redelivery must complete before reporting success.");
	}

	#[Then('user :email should not have a Redeliver button')]
	public function noClientRedeliveryButton(string $email):void {
		$this->expect($this->clientNotificationForm($email)->getParent()->hasAttribute("hidden"),
			"A successful notification must not offer Redeliver.");
	}

	private function clientNotificationForm(string $email):\Behat\Mink\Element\NodeElement {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select id from User where email = ? and applicationDeploymentId = 'managed-development'");
		$query->execute([$email]);
		$selector = '#all-users .user-sync-error input[name="id"][value="' . $query->fetchColumn() . '"]';
		return $this->assertSession()->elementExists("css", $selector)->getParent();
	}

	#[When('I open user details for :email')]
	public function openUserDetails(string $email):void {
		$form = $this->clientNotificationForm($email);
		$popup = $form->find("xpath", "ancestor::div[@popover]");
		$this->assertSession()->elementExists("css", 'button[data-icon=edit][popovertarget="' . $popup->getAttribute("id") . '"]')->click();
		$this->expect($popup->isVisible(), "User details must open.");
		if($directory = getenv("UI_SCREENSHOT_DIR")) {
			$this->saveScreenshot("user-details.png", $directory);
		}
	}

	#[Then('the URL and popover should identify user :id')]
	public function userDetailsUrl(string $id):void {
		$this->expect($this->getSession()->wait(5000, 'new URL(location.href).searchParams.get("user") === '
			. json_encode($id) . ' && document.querySelector(".user-details:popover-open")?.id === '
			. json_encode("user-details-" . $id)), "The URL must reopen the selected user details.");
	}

	#[When("I close the user details")]
	public function closeUserDetails():void {
		$id = $this->getSession()->evaluateScript('document.querySelector(".user-details:popover-open")?.id');
		$this->assertSession()->elementExists("css", "#" . $id)->pressButton("Close user details");
	}

	#[Then("the URL should not select a user popover")]
	public function noUserDetailsUrl():void {
		$this->expect($this->getSession()->wait(5000,
			'!new URL(location.href).searchParams.has("user") && !document.querySelector(".user-details:popover-open")'),
			"Closing details must remove the selected user from the URL.");
	}

	#[When("I go back in user detail history")]
	public function backInUserDetails():void {
		$this->getSession()->back();
	}

	#[When("I go forward in user detail history")]
	public function forwardInUserDetails():void {
		$this->getSession()->forward();
	}

	#[Then("sudo should return to user details with Delete enabled")]
	public function sudoReturnsToUserDetails():void {
		$this->expect($this->getSession()->wait(5000, 'location.pathname === "/admin/users/" && new URL(location.href).searchParams.has("user")'),
			"Sudo must preserve the selected user in the URL.");

		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			$id = $this->getSession()->evaluateScript('new URL(location.href).searchParams.get("user")');
			$this->assertSession()->elementExists("css", 'button[popovertarget="user-details-' . $id . '"][data-icon=edit]')->click();
		}

		$this->expect($this->getSession()->evaluateScript('document.querySelector(".user-details:popover-open .user-delete")?.disabled === false'),
			"The original user popover must reopen with Delete enabled.");
		$this->assertSession()->elementNotExists("css", "#delete-dialog[open], #delete-confirmation:not([hidden])");
	}

	#[Then("the user delete button should require sudo")]
	public function userDeleteRequiresSudo():void {
		$this->expect($this->getSession()->evaluateScript('document.querySelector(".user-details:popover-open .user-delete")?.disabled === true'),
			"Delete must be disabled before sudo authentication.");
	}

	#[When("I bypass the disabled user delete control")]
	public function bypassUserDeleteControl():void {
		$this->getSession()->executeScript('(() => {
			const button = document.querySelector(".user-details:popover-open .user-delete");
			const confirmed = document.createElement("input");
			confirmed.name = "confirmDelete";
			confirmed.value = button.dataset.confirmDelete;
			button.form.append(confirmed);
			button.disabled = false;
			button.removeAttribute("data-confirm-delete");
			button.form.requestSubmit(button);
		})()');
		$this->expect($this->getSession()->wait(5000, 'location.pathname === "/login/sudo/"'),
			"A confirmed request without sudo must require password authentication.");
	}

	#[When("I request sudo for user deletion")]
	public function requestUserDeletionSudo():void {
		$id = $this->getSession()->evaluateScript('document.querySelector(".user-details:popover-open")?.id');
		$this->assertSession()->elementExists("css", "#" . $id)->pressButton("Enable sudo to delete");
		$this->expect($this->getSession()->wait(5000, 'location.pathname === "/login/sudo/"'),
			"Deletion must request password authentication.");
	}

	#[When("I view the email in user details")]
	public function viewUserDetailsEmail():void {
		$id = $this->getSession()->evaluateScript('document.querySelector(".user-details:popover-open")?.id');
		$this->assertSession()->elementExists("css", "#" . $id . " [data-sudo-view]")->press();
		$this->expect($this->getSession()->wait(5000, 'location.pathname === "/login/sudo/"'),
			"The details email must require sudo authorisation.");
	}

	#[Then('the details for :email should return with a visible email')]
	public function userDetailsSudoReturn(string $email):void {
		$this->expect($this->getSession()->wait(5000, 'location.pathname === "/admin/users/"'),
			"Sudo must return to the Users page.");

		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			$this->openUserDetails($email);
		}

		$this->expect($this->getSession()->evaluateScript('document.querySelector(".user-details:popover-open [data-sudo=email]")?.textContent') === $email,
			"Sudo must reveal the requested details email and reopen the enhanced popover.");
	}

	#[Then("the user details should show their login history and deployment")]
	public function userDetailsHistory():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const popup = document.querySelector(".user-details:popover-open");
			const logins = popup.querySelectorAll(".user-login-history time");
			return popup.textContent.includes("Development") && !popup.textContent.includes("Private")
				&& logins.length === 1 && !Number.isNaN(Date.parse(logins[0].dateTime))
				&& popup.querySelector("[data-sudo=email]").textContent === "al•••@ex••••••••••";
		})()'), "Details must show successful timestamps and authorised deployments while protecting email.");
	}

	#[Then("the user details should fit mobile and desktop screens")]
	public function userDetailsLayout():void {
		foreach([390, 1440] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$this->expect($this->getSession()->evaluateScript('(() => {
				const popup = document.querySelector(".user-details:popover-open");
				const rect = popup.getBoundingClientRect();
				return rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight
					&& popup.scrollWidth <= popup.clientWidth + 1
					&& document.querySelectorAll(".user-details:popover-open").length === 1;
			})()'), "User details must fit a $width pixel viewport without horizontal scrolling.");
			if($directory = getenv("UI_SCREENSHOT_DIR")) {
				$this->saveScreenshot("user-details-$width.png", $directory);
			}
		}
	}

	#[Then('user :email should have a synchronisation error indicator')]
	public function userSyncErrorIndicator(string $email):void {
		$popup = $this->clientNotificationForm($email)->find("xpath", "ancestor::div[@popover]");
		$row = $this->assertSession()->elementExists("css", 'button[data-icon=edit][popovertarget="' . $popup->getAttribute("id") . '"]')
			->find("xpath", "ancestor::tr");
		$this->expect(str_contains($row->getAttribute("class"), "sync-error"), "A failed row must be tinted.");
		$this->expect(!$row->find("css", "[data-icon=cloud-x]")->hasAttribute("hidden"), "A failed row must show cloud-x.");
		$this->expect($this->clientNotificationForm($email)->find("css", "button[value=redeliver]") !== null,
			"Redeliver belongs in the synchronisation notice.");
	}

	#[Then('I should see :message in the user deletion confirmation')]
	public function userDeletionConfirmationMessage(string $message):void {
		$this->expect($this->deletionConfirmation()->find("css", "h2")->getText() === $message,
			"The confirmation must identify the saved user during sudo mode.");
	}

	#[Then("the user confirmation should close when sudo expires")]
	public function userConfirmationExpires():void {
		$this->expect($this->getSession()->wait(9000,
			'Date.now() >= Number(document.querySelector("[data-sudo-disable]").dataset.sudoUntil) * 1000 + 100'
			. ' && !document.querySelector("#delete-dialog[open]") && document.getElementById("delete-dialog-title").textContent === ""'),
			"Sudo expiry must close the user confirmation.");
		$this->expect($this->getSession()->evaluateScript('document.getElementById("delete-dialog-title").textContent') === "",
			"A closed confirmation must not retain the revealed email.");
	}

	#[When('I delete the user from their details')]
	public function deleteUserFromDetails():void {
		$id = $this->getSession()->evaluateScript('document.querySelector(".user-details:popover-open")?.id');
		$popup = $this->assertSession()->elementExists("css", "#" . $id);

		if($popup->findButton("Delete user")->hasAttribute("disabled")) {
			$popup->pressButton("Enable sudo to delete");
			$this->expect($this->getSession()->wait(5000, 'location.pathname === "/login/sudo/"'),
				"Deletion must require sudo first.");
			$this->fillField("Password", "Test-admin-password-2026");
			$this->pressButton("Enable sudo mode");
			$this->expect($this->getSession()->wait(5000, 'location.pathname === "/admin/users/"'),
				"Sudo must return to user details.");
			$popup = $this->assertSession()->elementExists("css", "#" . $id);

			if(getenv("BEHAT_JAVASCRIPT") !== "1") {
				$this->assertSession()->elementExists("css", 'button[data-icon=edit][popovertarget="' . $id . '"]')->click();
			}
		}

		$popup->pressButton("Delete user");
		$this->expect($this->getSession()->wait(5000,
			'document.querySelector("#delete-dialog[open], #delete-confirmation:not([hidden])") !== null'),
			"Deleting a user must first open confirmation.");
	}

	#[Then('user :email should exist in the provider and client')]
	public function userExistsInBoth(string $email):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		foreach(["User", "ClientUser"] as $table) {
			$query = $database->prepare("select count(*) from $table where email = ?");
			$query->execute([$email]);
			$this->expect((int)$query->fetchColumn() === 1, "$email must exist in $table.");
		}
	}

	#[Then('user :email should be deleted from the provider and client')]
	public function userDeletedFromBoth(string $email):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		foreach(["User", "ClientUser"] as $table) {
			$query = $database->prepare("select count(*) from $table where email = ?");
			$query->execute([$email]);
			$this->expect((int)$query->fetchColumn() === 0, "$email must be deleted from $table.");
		}
	}

	#[Then("stored user emails should be obscured without leaking their values")]
	public function obscuredUserEmails():void {
		$this->assertSession()->elementTextContains("css", "#all-users", "al•••@ex••••••••••");
		$this->assertSession()->elementTextContains("css", "#all-users", "bo•@ex••••••••••");
		$this->assertSession()->elementTextContains("css", "#all-users", "st•••••@ex••••••••••");
		$this->assertSession()->elementTextContains("css", "#login-failures", "bo•@ex••••••••••");
		$html = $this->getSession()->getPage()->getContent();

		foreach(["alice@example.test", "bob@example.test", "staging@example.test", "private@example.test"] as $email) {
			$this->expect(!str_contains($html, $email), "Protected email must not be present in HTML or attributes.");
		}

		$this->expect($this->getSession()->evaluateScript('Array.from(document.querySelectorAll("[data-sudo=email]")).every(field => {
			const group = field.closest(".sudo-control");
			return !group.querySelector("[data-sudo-view]").hidden && (!group.querySelector("[data-sudo-copy]") || group.querySelector("[data-sudo-copy]").hidden);
		})'), "Obscured emails must show the eye control rather than Copy.");
	}

	#[When("I view a protected user email")]
	public function viewUserEmail():void {
		$this->assertSession()->elementExists("css", "#all-users [data-sudo-view]")->press();
		$this->expect($this->getSession()->wait(5000, 'location.pathname === "/login/sudo/"'),
			"Viewing an email without sudo must require password confirmation.");
	}

	#[Then("all stored user emails should be visible")]
	public function visibleUserEmails():void {
		$this->expect($this->getSession()->wait(5000, 'document.querySelector("#all-users")?.textContent.includes("alice@example.test")'),
			"Email addresses must appear immediately after password confirmation.");
		$this->assertSession()->elementTextContains("css", "#all-users", "alice@example.test");
		$this->assertSession()->elementTextContains("css", "#all-users", "bob@example.test");
		$this->assertSession()->elementTextContains("css", "#all-users", "staging@example.test");
		$this->assertSession()->elementTextContains("css", "#login-failures", "bob@example.test");
	}

	#[Given("the Clipboard API is unavailable")]
	public function clipboardUnavailable():void {
		$this->getSession()->executeScript('Object.defineProperty(navigator, "clipboard", {value: undefined, configurable: true})');
	}

	#[Then("visible email controls should copy instead of viewing")]
	public function copyVisibleUserEmail():void {
		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			$this->assertSession()->elementNotExists("css", "[data-sudo-copy]");
			return;
		}

		$this->expect($this->getSession()->evaluateScript('Array.from(document.querySelectorAll("[data-sudo=email]")).every(field => {
			const group = field.closest(".sudo-control");
			return getComputedStyle(group.querySelector("[data-sudo-view]")).display === "none"
				&& getComputedStyle(group.querySelector("[data-sudo-copy]")).display !== "none";
		})'), "Every revealed email must replace its eye with Copy.");
		$this->getSession()->executeScript('if(navigator.clipboard) {
			const write = navigator.clipboard.writeText.bind(navigator.clipboard);
			navigator.clipboard.writeText = async value => { await write(value); window.copiedEmail = value; };
		}
		else {
			document.addEventListener("copy", () => { window.copiedEmail = document.activeElement.value; }, {once: true});
		}');
		$this->assertSession()->elementExists("css", "#all-users [data-sudo-copy]")->click();
		$this->expect($this->getSession()->wait(5000, 'window.copiedEmail !== undefined'), "Copy must write to the clipboard.");
		$this->expect($this->getSession()->evaluateScript("window.copiedEmail") === "alice@example.test",
			"The clipboard must receive the full email address.");
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$count = $database->query("select count(*) from Audit where action = 'SUDO_FIELD_ACCESSED' and userId = 'administrator' and json_extract(detail, '$.action') = 'copy'")->fetchColumn();
		$this->expect((int)$count > 0, "Copying a protected email must be audited.");
	}

	#[Then("email view icons should appear on hover and keyboard focus")]
	public function userEmailIcons():void {
		$field = $this->assertSession()->elementExists("css", "#all-users [data-sudo=email]");
		$this->expect($this->getSession()->evaluateScript('getComputedStyle(document.querySelector("#all-users [data-sudo-view]")).opacity === (matchMedia("(hover: none)").matches ? "0.5" : "0")'),
			"The eye icon should be unobtrusive before hovering.");
		$field->mouseOver();
		$this->expect($this->getSession()->evaluateScript('Number(getComputedStyle(document.querySelector("#all-users [data-sudo-view]")).opacity) > 0'),
			"Hovering the email must show the eye icon.");
		$this->getSession()->executeScript('document.querySelector("#all-users [data-sudo-view]").focus()');
		$this->expect($this->getSession()->evaluateScript('getComputedStyle(document.querySelector("#all-users [data-sudo-view]")).opacity === "1"'),
			"Keyboard focus must show the eye icon clearly.");
	}

	#[When('I search users for :search')]
	public function searchUsers(string $search):void {
		if(getenv("BEHAT_JAVASCRIPT") === "1") {
			$this->getSession()->executeScript('window.usersResponseReceived = false; document.addEventListener("flux:after-render", () => window.usersResponseReceived = true, {once: true})');
		}

		$this->getSession()->getPage()->find("css", ".user-filters")->fillField("search", $search);

		if(getenv("BEHAT_JAVASCRIPT") === "1") {
			$this->getSession()->getPage()->find("css", "#all-users h2")->click();
			$this->expect($this->getSession()->wait(5000, "window.usersResponseReceived === true"),
				"Changing the email search must refresh results automatically.");
		}
		else {
			$this->pressButton("Search Users");
		}
	}

	#[When('I filter users by deployment :deployment')]
	public function filterUserDeployment(string $deployment):void {
		if(getenv("BEHAT_JAVASCRIPT") === "1") {
			$this->getSession()->executeScript('window.usersResponseReceived = false; document.addEventListener("flux:after-render", () => window.usersResponseReceived = true, {once: true})');
		}

		$this->getSession()->getPage()->find("css", ".user-filters")->selectFieldOption("userDeployment", $deployment);

		if(getenv("BEHAT_JAVASCRIPT") === "1") {
			$this->expect($this->getSession()->wait(5000, "window.usersResponseReceived === true"),
				"Changing a user filter must refresh results automatically.");
		}
		else {
			$this->pressButton("Search Users");
		}
	}

	#[Then('the deployment filter should show :deployment with only this application\'s deployments')]
	public function userDeploymentOptions(string $deployment):void {
		$select = $this->assertSession()->elementExists("css", ".user-filters select[name=userDeployment]");
		$selected = $select->find("css", "option[selected]");
		$this->expect($selected && $selected->getText() === $deployment, "The selected deployment must be retained.");
		$options = array_map(fn($option) => $option->getText(), $select->findAll("css", "option"));
		$this->expect($options === ["All deployments", "Development", "Staging"], "Only the selected application's deployments may be offered.");
	}

	#[Then("user search should adapt to narrow and wide containers")]
	public function userSearchLayout():void {
		$this->getSession()->resizeWindow(1440, 1000);
		$enhanced = getenv("BEHAT_JAVASCRIPT") === "1" ? "true" : "false";

		foreach([360, 720] as $width) {
			$this->getSession()->executeScript("document.querySelector('#all-users').style.inlineSize = '$width' + 'px'");
			$this->expect($this->getSession()->evaluateScript(<<<JS
(() => {
	const form = document.querySelector('.user-filters');
	const [email, deployment, sort] = [...form.querySelectorAll('label')].map(label => label.getBoundingClientRect());
	const button = form.querySelector('button');
	const bounds = form.getBoundingClientRect();
	const actions = form.querySelector('.actions').getBoundingClientRect();
	const buttonBounds = button.getBoundingClientRect();
	const same = (first, second) => Math.abs(first - second) < 2;
	const wide = $width === 720;
	const controlsFit = wide
		? same(email.bottom, deployment.bottom) && same(deployment.bottom, sort.bottom)
		: same(email.width, bounds.width) && deployment.top >= email.bottom && same(deployment.top, sort.top);
	const enhanced = $enhanced;
	return controlsFit && (enhanced
		? getComputedStyle(button).display === 'none' && actions.height === 0
		: buttonBounds.top >= sort.bottom && same(buttonBounds.right, bounds.right)
			&& (wide ? buttonBounds.width < bounds.width : same(buttonBounds.width, bounds.width)));
})()
JS), "User search must follow its container width and keep the correct scripting fallback.");
		}
	}

	#[Then("the Users page should fit narrow and wide screens")]
	public function usersLayout():void {
		foreach([1440, 390, 280] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$this->expect($this->getSession()->evaluateScript("document.documentElement.scrollWidth <= innerWidth"),
				"The Users page must fit a $width pixel viewport.");
			$this->expect($this->getSession()->evaluateScript('(() => {
				const panels = ["logged-in-users", "login-failures", "all-users", "add-user"]
					.map(id => document.getElementById(id).getBoundingClientRect());
				const [current, failures, users, add] = panels;
				const same = (first, second) => Math.abs(first - second) < 2;
				return same(users.left, current.left) && same(users.width, current.width)
					&& same(add.left, failures.left) && same(add.width, failures.width)
					&& (same(current.top, failures.top) ? same(users.top, add.top) : add.top >= users.bottom);
			})()'), "Both rows of Users panels must share their column alignment.");
			$this->expect($this->getSession()->evaluateScript('(() => {
				const table = document.querySelector("#all-users table");
				const labels = Array.from(table.querySelectorAll("thead th"), header => header.textContent);
				return Array.from(table.querySelectorAll("tbody tr")).every(row =>
					Array.from(row.cells).every((cell, index) => cell.dataset.label === labels[index]));
			})()'), "Collapsed user cells must identify their values with the matching column labels.");
			$this->expect($this->getSession()->evaluateScript('Array.from(document.querySelectorAll("#add-user-form .choice")).every(label => {
				const input = label.querySelector("input");
				const span = label.querySelector("span");
				const range = document.createRange();
				range.selectNodeContents(span);
				const text = Array.from(range.getClientRects()).find(rect => rect.width > 0);
				const box = input.getBoundingClientRect();
				return getComputedStyle(label).display === "grid"
					&& Math.abs(box.top + box.height / 2 - text.top - text.height / 2) <= 3;
			})'), "Checkboxes must align with the first line of their labels at $width pixels.");
			$this->expect($this->getSession()->evaluateScript('(() => {
				const form = document.querySelector("#add-user-form");
				const fields = Array.from(form.querySelectorAll(":scope > label"));
				const gap = parseFloat(getComputedStyle(form).rowGap);
				return form.querySelectorAll(".choice").length === 2 && gap >= 16
					&& fields.every((field, index) => index === 0
						|| Math.abs(field.getBoundingClientRect().top - fields[index - 1].getBoundingClientRect().bottom - gap) < 2);
			})()'), "Checkboxes must share the other fields' spacing at $width pixels.");
		}
	}

	#[When('I submit the Users page with :button')]
	public function submitUsersForm(string $button):void {
		if(getenv("BEHAT_JAVASCRIPT") === "1") {
			$this->getSession()->executeScript('window.usersResponseReceived = false; document.addEventListener("flux:after-render", () => window.usersResponseReceived = true, {once: true})');
		}

		$this->pressButton($button);

		if(getenv("BEHAT_JAVASCRIPT") === "1") {
			$this->expect($this->getSession()->wait(5000, "window.usersResponseReceived === true"),
				"The Users form response must finish rendering.");
		}
	}

	#[When("I enable the new user password field")]
	public function enableUserPassword():void {
		$this->checkOption("Set password");

		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			$this->pressButton("Apply authentication options");
		}

		$this->expect($this->getSession()->getPage()->findField("Password")->isVisible(),
			"The password field must appear when Set password is selected.");
	}

	#[When('I log in to deployment :deployment as :email with password :password')]
	public function loginDeploymentUser(string $deployment, string $email, string $password):void {
		$this->visit("/?dev-login=" . rawurlencode($deployment));
		$this->fillField("Email address", $email);
		$this->pressButton("Continue");
		$this->expect($this->getSession()->wait(5000, "document.querySelector('input[name=password]') !== null"),
			"The user must be offered password login immediately.");
		$this->fillField("Password", $password);
		$this->pressButton("Log in with password");
		$this->expect($this->getSession()->wait(5000, "document.querySelector('#auth-title')?.textContent.includes(\"You're logged in!\")"),
			"The administrator's chosen password must complete a real login.");
	}

	#[Then('user :email should have password :password and no queued instructions')]
	public function createdPasswordUser(string $email, string $password):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select hash from User where email = ?");
		$query->execute([$email]);
		$this->expect(password_verify($password, $query->fetchColumn()), "The chosen password must work immediately.");
		$this->expect((int)$database->query("select count(*) from Email")->fetchColumn() === 0,
			"Unchecked instructions must not queue an email.");
		$this->expect((int)$database->query("select count(*) from UserAuthCode")->fetchColumn() === 0,
			"Manual creation must not require a verification code.");
		$this->assertNoUserCredentialsInAudit($database, $password);
	}

	#[Then('user :email should have email login instructions and no password')]
	public function createdEmailUser(string $email):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select hash from User where email = ?");
		$query->execute([$email]);
		$this->expect($query->fetchColumn() === null, "An email-only user must not have a password.");
		$query = $database->prepare("select subject, textContent, htmlContent from Email where toEmail = ?");
		$query->execute([$email]);
		$message = $query->fetch(PDO::FETCH_OBJ);
		$this->expect($message && str_contains($message->subject, "My website")
			&& str_contains($message->textContent, "email security code")
			&& str_contains($message->htmlContent, 'href="' . rtrim(getenv("BEHAT_CLIENT_URL"), "/") . '/login/"')
			&& !str_contains($message->htmlContent, "{{"), "Instructions must contain the application login link and email method.");
	}

	private function assertNoUserCredentialsInAudit(PDO $database, string $password):void {
		foreach($database->query("select detail from Audit")->fetchAll(PDO::FETCH_COLUMN) as $detail) {
			$this->expect(!str_contains($detail ?? "", $password), "Audit records must never contain the password.");
		}
	}

	#[Then('user :email should not exist')]
	public function absentUser(string $email):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select count(*) from User where email = ?");
		$query->execute([$email]);
		$this->expect((int)$query->fetchColumn() === 0, "Rejected user data must not be persisted.");
	}

	#[When("I attempt to add the user")]
	public function attemptUserCreation():void {
		$this->deploymentPostCount = $this->deploymentPosts();
		$this->pressButton("Add user");
	}

	#[Then("the user password should fail native validation without a request")]
	public function nativeUserPasswordValidation():void {
		$this->expect($this->getSession()->evaluateScript('document.querySelector("#add-user-form [name=password]").validity.tooShort'),
			"The browser must reject a password shorter than twelve characters.");
		$this->noDeploymentRequest();
	}

	#[When("I add the user without browser validation")]
	public function bypassUserValidation():void {
		$this->getSession()->evaluateScript('document.querySelector("#add-user-form").noValidate = true');
		$this->submitUsersForm("Add user");
	}

	#[Then('the login page should identify application :name')]
	public function loginApplicationName(string $name):void {
		$this->assertSession()->elementTextContains("css", "#main-content", $name);
		$this->expect($this->getSession()->getPage()->find("css", ".logo")->getAttribute("alt") === "$name logo",
			"The logo must describe the application, rather than the deployment.");
		$this->expect($this->getSession()->getPage()->find("css", "title")->getText() === "$name - Login",
			"The browser title must name the application.");
	}

	#[Then('the login page should not show a deployment badge')]
	public function noLoginDeploymentBadge():void {
		$notice = $this->assertSession()->elementExists("css", ".deployment-notice");
		$this->expect(!$notice->isVisible(), "Production must not show a deployment badge.");
	}

	#[Then('the login page should show deployment badge :name above its content at width :width')]
	public function loginDeploymentBadge(string $name, int $width):void {
		$this->getSession()->resizeWindow($width, 1000);
		$notice = $this->assertSession()->elementExists("css", ".deployment-notice");
		$this->expect($notice->isVisible() && $notice->getText() === $name, "Missing non-production deployment badge.");
		$this->expect($this->getSession()->evaluateScript(<<<'JS'
(() => {
	const notice = document.querySelector('.deployment-notice');
	const main = document.querySelector('#main-content');
	const badge = notice.querySelector('.badge');
	return !main.contains(notice) && notice.getBoundingClientRect().bottom <= main.getBoundingClientRect().top
		&& parseFloat(getComputedStyle(badge).borderRadius) > 0
		&& document.documentElement.scrollWidth <= innerWidth;
})()
JS), "The deployment badge must be above the main content and fit the viewport.");
	}

	#[Given('production client URLs use the test server')]
	public function productionClientUrls():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$port = parse_url($this->getMinkParameter("base_url"), PHP_URL_PORT);
		$query = $database->prepare("update ApplicationDeployment set providerHost = ?, clientHost = ?, clientLoginPath = '/logged-out/' where id = 'managed-development'");
		$query->execute(["login.production.test:$port", $this->getMinkParameter("base_url")]);
		$query = $database->prepare("update User set hash = ? where id = 'customer'");
		$query->execute([password_hash("Test-customer-password-2026", PASSWORD_DEFAULT)]);
	}

	#[When('I start a client login on the production provider')]
	public function productionClientLogin():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$deployment = $database->query("select id, secret, providerHost from ApplicationDeployment where id = 'managed-development'")->fetch(PDO::FETCH_OBJ);
		$iv = new \GT\Cipher\InitVector();
		$payload = http_build_query(["action" => "login", "secretIv" => (string)new \GT\Cipher\InitVector()]);
		$message = new \GT\Cipher\Message\PlainTextMessage($payload, $iv);
		$query = http_build_query([
			"c" => (string)$message->encrypt(new \GT\Cipher\Key($deployment->secret)),
			"i" => (string)$iv,
			"d" => $deployment->id,
			"u" => bin2hex($this->getMinkParameter("base_url") . "/logged-out/"),
		]);
		$this->visit("http://$deployment->providerHost/?$query");
	}

	#[Then('the Users page should show :count active client sessions')]
	public function activeClientSessions(int $count):void {
		$label = $count === 1 ? "1 active session" : "$count active sessions";
		$this->assertSession()->elementTextContains("css", "#logged-in-users", $label);
		$this->assertSession()->elementsCount("css", "#logged-in-users .record-list > li", $count);
	}

	#[Then('the current users list should show :count distinct users and :message')]
	public function currentUsersLimit(int $count, string $message):void {
		$this->assertSession()->elementsCount("css", "#logged-in-users .current-users > li", $count);
		$this->assertSession()->elementTextContains("css", "#logged-in-users", $message);
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select userId from UserSession where id=?");
		$userIds = [];

		foreach($this->getSession()->getPage()->findAll("css", ".current-users [data-sudo=email]") as $field) {
			$sessionId = substr($field->getAttribute("id"), strlen("session-"), -strlen("-email"));
			$query->execute([$sessionId]);
			$userIds []= $query->fetchColumn();
		}

		$this->expect(count(array_unique($userIds)) === $count,
			"Each user must appear once even with several active browser sessions.");
	}

	#[Then('the current users list should scroll five rows at a time')]
	public function currentUsersScroll():void {
		foreach([1440, 390] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$this->expect($this->getSession()->evaluateScript('(() => {
				const list = document.querySelector(".current-users");
				const rows = [...list.children];
				const height = rows[0].getBoundingClientRect().height;
				const fiveFit = Math.abs(list.clientHeight - height * 5) <= 1;
				list.scrollTop = list.scrollHeight;
				return fiveFit && list.scrollHeight > list.clientHeight && list.scrollTop > 0;
			})()'), "Only five user rows should fit in the scroll area at $width pixels.");
		}
	}

	#[When('I log out from the production client')]
	public function productionClientLogout():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$deployment = $database->query("select id, secret, providerHost from ApplicationDeployment where id='managed-development'")->fetch(PDO::FETCH_OBJ);
		$iv = new \GT\Cipher\InitVector();
		$message = new \GT\Cipher\Message\PlainTextMessage(http_build_query([
			"action" => "logout", "redirectTo" => "/logged-out/",
		]), $iv);
		$query = http_build_query([
			"c" => (string)$message->encrypt(new \GT\Cipher\Key($deployment->secret)),
			"i" => (string)$iv, "d" => $deployment->id,
		]);
		$this->visit("http://$deployment->providerHost/?$query");
	}

	#[When('the production client session expires')]
	public function expireClientSession():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("update UserSession set expiresAt=? where userId='customer'");
		$query->execute([(new DateTimeImmutable("-1 second"))->format("Y-m-d H:i:s")]);
	}

	#[Then("the production customer's session logout should be audited")]
	public function auditedClientSessionLogout():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$row = $database->query("select userId, detail from Audit where action='LOGOUT_REQUESTED' and userId='customer'")->fetch(PDO::FETCH_OBJ);
		$this->expect($row && isset(json_decode($row->detail)->sessionId),
			"Client logout must identify the user and the ended session even after the provider session was cleared.");
		$this->expect((int)$database->query("select count(*) from UserSession where userId='customer' and endedAt is not null")->fetchColumn() === 1,
			"The persisted client session must be ended.");
	}

	#[When('I visit the production provider directly')]
	public function visitProductionProvider():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$host = $database->query("select providerHost from ApplicationDeployment where id = 'managed-development'")->fetchColumn();
		$this->visit("http://$host/");
	}

	#[When('I start a development login on the production provider')]
	public function productionDevelopmentLogin():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$host = $database->query("select providerHost from ApplicationDeployment where id = 'managed-development'")->fetchColumn();
		$this->visit("http://$host/?dev-login=managed-development");
	}

	#[When('I sign in as the production customer')]
	public function productionCustomerSignIn():void {
		$this->fillField("Email address", "customer@example.test");
		$this->pressButton("Continue");
		$this->getSession()->wait(5000, "document.querySelector('input[name=password]') !== null");
		$this->fillField("Password", "Test-customer-password-2026");
		$this->pressButton("Log in with password");
		$this->expect($this->getSession()->wait(5000,
			"location.pathname === '/logged-out/' || location.pathname === '/login/success/'"),
			"The customer's authentication must complete.");
	}

	#[Then('production client progress should have :count saved milestones with audits')]
	public function productionClientProgress(int $count):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$row = $database->query("select clientConnected, userLoggedIn from ApplicationDeployment where id = 'managed-development'")->fetch(PDO::FETCH_OBJ);
		$this->expect((bool)$row->clientConnected === ($count >= 1), "Incorrect saved client-connection progress.");
		$this->expect((bool)$row->userLoggedIn === ($count >= 2), "Incorrect saved first-login progress.");
		foreach(["CLIENT_CONNECTED", "FIRST_CLIENT_LOGIN"] as $index => $action) {
			$query = $database->prepare("select userId, anonUserId, detail from Audit where action = ?");
			$query->execute([$action]);
			$rows = $query->fetchAll(PDO::FETCH_OBJ);
			$this->expect(count($rows) === ($count > $index ? 1 : 0), "Incorrect audit count for $action.");
			if($rows) {
				$this->expect($index === 0 ? $rows[0]->anonUserId !== null : $rows[0]->userId === "customer",
					"The milestone must identify its actual actor.");
				$this->expect(!str_contains($rows[0]->detail, "secret"), "Milestone audits must not contain credentials.");
			}
		}
	}

	#[Then('requesting domain instructions on the hosted provider should identify the hosted provider')]
	public function hostedDomainInstructions():void {
		$request = curl_init($this->getSession()->getCurrentUrl());
		curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ["Host: login.authwave.com"],
			CURLOPT_COOKIE => "GT=" . $this->getSession()->getCookie("GT")]);
		$html = curl_exec($request);
		$this->expect(curl_getinfo($request, CURLINFO_HTTP_CODE) === 200 && is_string($html),
			"The hosted-host request must use the authenticated test session.");
		$document = new \GT\Dom\HTMLDocument($html);
		$message = $document->querySelector("#domain-help-managed-development p")->textContent;
		$this->expect($message === "You are using the hosted Authwave provider", "Incorrect hosted-provider message.");
	}

	private int $domainCheckCount = 0;

	private function domainChecks():int {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		return (int)$database->query("select count(*) from Audit where action = 'DOMAIN_VERIFICATION_CHECKED'")->fetchColumn();
	}

	#[When('I start checking the deployment domain')]
	public function startDomainCheck():void {
		$this->domainPopover()->pressButton("I've set these records");
		$this->getSession()->wait(5000, 'document.querySelector(".domain-verification[data-flux=live]") !== null');
	}

	#[Then('the deployment domain should be checked every ten seconds while this page is open')]
	public function domainPolling():void {
		$this->deploymentDomainStatus("Development", "Checking domain...");
		$region = $this->assertSession()->elementExists("css", ".domain-verification[data-flux=live]");
		$this->expect($region->getAttribute("data-flux-rate") === "10", "DNS checks must use a ten-second Flux rate.");
		$this->expect($region->find("css", ".verification-spinner")->isVisible(), "Pending verification needs its spinner.");
		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			$before = $this->domainChecks();
			$region->pressButton("Check again");
			$this->expect($this->domainChecks() === $before + 1, "Native verification must support a manual recheck.");
		}
	}

	#[Then('domain verification polling should not discard unsaved deployment fields')]
	public function domainPollingPreservesDraft():void {
		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			return;
		}
		$details = $this->deploymentDetails("Development");
		$details->fillField("Deployment name", "Unsaved title");
		$token = $this->getSession()->evaluateScript('document.querySelector("meta[name=csrf-token]").content');
		$before = $this->domainChecks();
		$deadline = microtime(true) + 12;
		while($this->domainChecks() === $before && microtime(true) < $deadline) {
			usleep(100000);
		}
		$this->expect($this->domainChecks() > $before, "Flux must perform another DNS check without user interaction.");
		$this->expect($this->deploymentDetails("Development")->findField("Deployment name")->getValue() === "Unsaved title",
			"A status refresh must preserve unsaved deployment values.");
		$this->expect($this->getSession()->wait(5000, 'document.querySelector("meta[name=csrf-token]").content !== ' . json_encode($token)),
			"Live responses must renew CSRF tokens without replacing editable fields.");
		$this->domainCheckCount = $this->domainChecks();
	}

	#[Then('leaving the page should stop domain verification checks')]
	public function domainPollingStops():void {
		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			return;
		}
		$this->getSession()->wait(11000, 'false');
		$this->expect($this->domainChecks() === $this->domainCheckCount, "Leaving the page must stop DNS checks.");
	}

	private function domainPopover():\Behat\Mink\Element\NodeElement {
		foreach($this->getSession()->getPage()->findAll("css", ".domain-verification-help") as $popover) {
			if($popover->isVisible()) {
				return $popover;
			}
		}

		throw new RuntimeException("No domain verification popover is open.");
	}

	#[Then('deployment :title should show domain status :status')]
	public function deploymentDomainStatus(string $title, string $status):void {
		$this->expect($this->deploymentDetails($title)->find("css", ".domain-verification p")->getText() === $status,
			"Unexpected domain verification status.");
	}

	#[Then('deployment :title should have a :badge badge')]
	public function deploymentBadge(string $title, string $badge):void {
		$labels = $this->deploymentDetails($title)->findAll("css", "summary .badge:not([hidden])");
		$this->expect(in_array($badge, array_map(fn($label) => $label->getText(), $labels), true), "Missing $badge badge.");
	}

	#[Then('deployment :title should not have a :badge badge')]
	public function missingDeploymentBadge(string $title, string $badge):void {
		$labels = $this->deploymentDetails($title)->findAll("css", "summary .badge:not([hidden])");
		$this->expect(!in_array($badge, array_map(fn($label) => $label->getText(), $labels), true), "Unexpected $badge badge.");
	}

	#[When('I open the domain verification instructions for deployment :title')]
	public function openDomainInstructions(string $title):void {
		$this->deploymentDetails($title)->pressButton("Begin verification");
	}

	#[Then('the domain verification popover should show the DNS records for :host')]
	public function domainInstructions(string $host):void {
		$popover = $this->domainPopover();
		$rows = $popover->findAll("css", "tbody tr");
		$this->expect(count($rows) === 2, "Both nameserver records must be shown.");
		foreach($rows as $index => $row) {
			$values = array_map(fn($cell) => $cell->getText(), $row->findAll("css", "td"));
			$this->expect($values === ["NS", $host, "ns" . ($index + 1) . ".authwave.com", "1800"], "Incorrect DNS instructions.");
		}
	}

	#[When('I cancel domain verification')]
	public function cancelDomainVerification():void {
		$this->domainPopover()->pressButton("Cancel");
	}

	#[Then('the domain verification popover should be closed')]
	public function closedDomainInstructions():void {
		foreach($this->getSession()->getPage()->findAll("css", ".domain-verification-help") as $popover) {
			$this->expect(!$popover->isVisible(), "The verification popover must be closed.");
		}
	}

	private function deploymentKey(string $id):string {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select secret from ApplicationDeployment where id = ?");
		$query->execute([$id]);
		$secret = $query->fetchColumn();
		$this->expect(is_string($secret) && $secret !== "", "The deployment must have a persisted key.");
		return $id . "_" . $secret;
	}

	#[Then('deployment :summary should have an obscured API key without leaking its value')]
	public function obscuredDeploymentKey(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$field = $details->findField("API key");
		$id = $details->findField("ID")->getValue();
		$key = $this->deploymentKey($id);
		$prefix = preg_match('/^[^_]+_[^_]+_/', $key, $match) ? substr($key, 0, strlen($match[0]) + 4) : "";
		$this->expect($field->getAttribute("name") === "apiKey", "The read-only key needs a name.");
		$this->expect(str_starts_with($field->getValue(), $prefix) && str_contains($field->getValue(), "•"),
			"Only the key prefix should be visible.");
		$this->expect(!str_contains($this->getSession()->getPage()->getHtml(), $key), "The full key leaked into the document.");
	}

	#[When('I view the API key of deployment :summary')]
	public function viewDeploymentKey(string $summary):void {
		$this->deploymentDetails($summary)->find("css", "[data-sudo-view]")->click();
		$revealed = getenv("BEHAT_JAVASCRIPT") === "1"
			? 'document.querySelector("[data-sudo-view][aria-pressed=true]") !== null'
			: 'document.querySelector("[data-sudo-enabled]") !== null';
		$this->getSession()->wait(5000, 'location.pathname === "/login/sudo/" || (' . $revealed . ')');
	}

	#[Then('sudo mode should be enabled')]
	public function sudoEnabled():void {
		$this->getSession()->wait(5000, 'document.querySelector("[data-sudo-disable]:not([hidden])") !== null');
		$this->openSidebar();
		$this->assertSession()->elementExists("css", "[data-sudo-disable]:not([hidden])");
	}

	#[Then('sudo mode should be disabled')]
	public function sudoDisabled():void {
		$this->assertSession()->elementNotExists("css", "[data-sudo-disable]:not([hidden])");
	}

	#[Then('the API key of deployment :summary should be visible')]
	public function visibleDeploymentKey(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$id = $details->findField("ID")->getValue();
		$key = $this->deploymentKey($id);
		$field = $details->findField("API key");
		$this->getSession()->wait(5000, 'document.getElementById(' . json_encode($field->getAttribute("id")) . ').value === ' . json_encode($key));
		$this->expect($field->getValue() === $key, "The authorised key was not revealed.");
	}

	private int $sudoDeadline = 0;

	#[When("I reload shortly before sudo expires")]
	public function reloadBeforeSudoExpiry():void {
		$this->sudoDeadline = (int)$this->assertSession()->elementExists("css", "[data-sudo-disable]")->getAttribute("data-sudo-until");
		$this->expect($this->getSession()->wait(5000, "Date.now() >= " . (($this->sudoDeadline - 2) * 1000)),
			"The test must approach the current idle deadline.");
		$this->reload();
	}

	#[When("I type shortly before sudo expires")]
	public function typeBeforeSudoExpiry():void {
		$this->sudoDeadline = (int)$this->assertSession()->elementExists("css", "[data-sudo-disable]")->getAttribute("data-sudo-until");
		$this->expect($this->getSession()->wait(5000, "Date.now() >= " . (($this->sudoDeadline - 2) * 1000)),
			"The test must approach the current idle deadline.");
		$field = $this->deploymentDetails("Development")->findField("Deployment name");
		$field->keyDown(16);
		$field->keyUp(16);
	}

	#[Then("the sudo inactivity deadline should be renewed")]
	public function sudoDeadlineRenewed():void {
		$this->expect($this->getSession()->wait(5000,
			'Number(document.querySelector("[data-sudo-disable]")?.dataset.sudoUntil) > ' . $this->sudoDeadline),
			"Activity must extend sudo beyond its previous deadline.");
		$this->sudoEnabled();
	}

	#[When("the provider refreshes automatically until sudo expires")]
	public function automaticRefreshDoesNotRenewSudo():void {
		$this->getSession()->executeScript('window.backgroundRequests = 0;
			window.backgroundRefresh = setInterval(async () => {
				const detail = {requestOptions: {}, historyState: {action: null}};
				document.dispatchEvent(new CustomEvent("flux:before-request", {detail}));
				await fetch(location.href, {...detail.requestOptions, cache: "no-store"});
				window.backgroundRequests++;
			}, 1000)');

		try {
			$this->expireSudo();
			$this->expect($this->getSession()->evaluateScript("window.backgroundRequests > 0"),
				"Automatic refresh requests must have completed while sudo expired.");
		}
		finally {
			$this->getSession()->executeScript('clearInterval(window.backgroundRefresh)');
		}
	}

	#[Then("sudo should only have required one password confirmation")]
	public function sudoAuthorisedOnce():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$this->expect((int)$database->query("select count(*) from Audit where action='SUDO_AUTHORISED'")->fetchColumn() === 1,
			"Continued activity must not request another password confirmation.");
	}

	#[When('the sudo period expires')]
	public function expireSudo():void {
		$this->expect($this->getSession()->wait(9000,
			'Date.now() >= Number(document.querySelector("[data-sudo-disable]").dataset.sudoUntil) * 1000 + 100'), "The test sudo period did not expire.");
		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			$this->reload();
		}
		$this->sudoDisabled();
	}

	#[When('I disable sudo mode')]
	public function disableSudo():void {
		$this->openSidebar();
		$this->pressButton("SUDO");
		$this->expect($this->getSession()->wait(5000,
			'document.readyState === "complete" && document.querySelector("#main-content") !== null && document.querySelector("[data-sudo-disable]:not([hidden])") === null'),
			"Disabling sudo must return to the page with authorisation removed.");
	}

	#[Then('the sudo audit trail should contain no credentials')]
	public function sudoAuditCredentials():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$details = $database->query("select detail from Audit where action like 'SUDO_%'")->fetchAll(PDO::FETCH_COLUMN);
		foreach($details as $detail) {
			$this->expect(!str_contains($detail ?? "", "Test-admin-password-2026")
				&& !str_contains($detail ?? "", "Incorrect-password-2026")
				&& !str_contains($detail ?? "", "0123456789abcdef0123456789abcdef"), "Sudo audit contains credentials.");
		}
	}

	#[When('I copy the API key of deployment :summary')]
	public function copyDeploymentKey(string $summary):void {
		$details = $this->deploymentDetails($summary);
		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			$this->expect($details->find("css", "[data-sudo-copy]") === null, "Copy must be a JavaScript enhancement.");
			return;
		}
		$details->find("css", "[data-sudo-copy]")->click();
		$this->getSession()->wait(5000, 'location.pathname === "/login/sudo/"');
	}

	#[Then('copying the API key should require sudo and copy the saved value')]
	public function copiedDeploymentKey():void {
		if(getenv("BEHAT_JAVASCRIPT") !== "1") {
			return;
		}
		$this->providerPath("/login/sudo/");
		$this->fillField("Password", "Test-admin-password-2026");
		$this->pressButton("Enable sudo mode");
		$this->sudoEnabled();
		$this->obscuredDeploymentKey("Development");
		$this->getSession()->executeScript('const write = navigator.clipboard.writeText.bind(navigator.clipboard); navigator.clipboard.writeText = async value => { await write(value); window.copiedSudoValue = value; };');
		$this->deploymentDetails("Development")->find("css", "[data-sudo-copy]")->click();
		$this->getSession()->wait(5000, 'window.copiedSudoValue !== undefined');
		$this->expect($this->getSession()->evaluateScript('window.copiedSudoValue') === $this->deploymentKey("managed-development"),
			"The clipboard API must receive the authorised saved key.");
		$this->obscuredDeploymentKey("Development");
	}

	#[Then('the sidebar should own its semantic landmark')]
	public function sidebarLandmark():void {
		$this->assertSession()->elementsCount("css", "admin-sidebar", 1);
		$this->assertSession()->elementExists("css", 'body > admin-sidebar > aside[aria-label="Application sidebar"] nav[aria-label="Administration"]');
		$this->assertSession()->elementNotExists("css", "body > aside > admin-sidebar");
	}

	#[Then('unfinished controls should be disabled')]
	public function unfinishedControlsDisabled():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const controls = [...document.querySelectorAll("main input:not([type=hidden]), main button, main select, main textarea")];
			return controls.length > 0 && controls.every(control => control.disabled);
		})()'), "Unfinished screens must not offer simulated actions.");
	}

	#[Then('prototype records should not be displayed')]
	public function noPrototypeRecords():void {
		$this->assertSession()->elementsCount("css", "main table tbody tr, main .record-list > li", 0);
		$this->assertSession()->elementsCount("css", 'main input[name="do"][value="demo"]', 0);
		$this->assertSession()->elementNotExists("css", 'a[download="sample-users.csv"]');
	}

	#[Then('the sidebar controls should fit narrow and wide screens')]
	public function sidebarLayout():void {
		foreach([1440, 390, 280] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$this->openSidebar();
			$this->expect($this->getSession()->evaluateScript('(() => {
				const header = document.querySelector(".sidebar-header").getBoundingClientRect();
				const nav = document.querySelector("nav[aria-label=Administration]").getBoundingClientRect();
				const account = document.querySelector(".sidebar-account").getBoundingClientRect();
				return nav.top >= header.bottom && account.top >= nav.bottom
					&& document.documentElement.scrollWidth <= document.documentElement.clientWidth;
			})()'), "Sidebar layout failed at $width pixels.");
		}
	}

	#[Then('the sidebar should provide the correct scripting fallback')]
	public function sidebarScriptingFallback():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const scriptsEnabled = matchMedia("(scripting: enabled)").matches;
			const submit = document.querySelector(".sidebar-header button[type=submit]");
			const theme = document.querySelector("[data-theme-toggle]");
			return (getComputedStyle(submit).display === "none") === scriptsEnabled
				&& theme.hidden === !scriptsEnabled;
		})()'), "Scope and theme controls must respect scripting availability.");
	}

	#[Given('the client login destination is the local logged-out page')]
	public function localClientLoginDestination():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("update ApplicationDeployment set clientHost = ?, clientLoginPath = '/logged-out/' where id = 'provider'");
		$query->execute([rtrim($this->getMinkParameter("base_url"), "/")]);
	}

	#[Then('the client login redirect should be audited')]
	public function auditedClientRedirect():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$row = $database->query("select detail, anonUserId from Audit where action = 'CLIENT_LOGIN_REDIRECTED'")->fetch(PDO::FETCH_ASSOC);
		$this->expect($row !== false, "Missing client login redirect audit.");
		$detail = json_decode($row["detail"], flags: JSON_THROW_ON_ERROR);
		$this->expect($detail->redirectTo === rtrim($this->getMinkParameter("base_url"), "/") . "/logged-out/",
			"Incorrect client redirect destination in audit.");
		$this->expect($detail->deploymentId === "provider" && $row["anonUserId"] !== null,
			"Missing deployment or anonymous actor in redirect audit.");
	}

	private ?DatabaseState $database = null;

	#[Given('the database is in the :state state')]
	public function databaseState(string $state):void {
		$this->getSession()->reset();
		$path = getenv("BEHAT_DATABASE");
		if(!$path) throw new RuntimeException("Run Behat using composer behat.");
		$this->database = new DatabaseState($path, dirname(__DIR__) . "/database");
		$this->database->apply($state);
		if($state === "user_administration") {
			$client = new PDO("sqlite:$path");
			$client->prepare("update ApplicationDeployment set clientHost = ?, clientLoginPath = '/login/' where id = 'managed-development'")
				->execute([rtrim(getenv("BEHAT_CLIENT_URL"), "/")]);
		}
		$connection = new PDO("sqlite:$path");
		$host = parse_url($this->getMinkParameter("base_url"), PHP_URL_HOST);
		$port = parse_url($this->getMinkParameter("base_url"), PHP_URL_PORT);
		$update = $connection->prepare("update ApplicationDeployment set providerHost = ? where id = 'provider'");
		$update->execute([$host . ($port ? ":$port" : "")]);
		$update = $connection->prepare("update User set hash = ? where id = 'administrator'");
		$update->execute([password_hash("Test-admin-password-2026", PASSWORD_DEFAULT)]);
	}

	#[AfterScenario]
	public function restoreDatabase():void {
		$this->getSession()->stop();
		$this->database?->restore();
	}

	#[Given('I sign in as the fixture administrator')]
	public function signIn():void {
		$this->visit("/admin/");
		$this->fillField("Email address", "admin@example.test");
		$this->pressButton("Continue");
		$this->getSession()->wait(5000, "document.querySelector('input[name=password]') !== null");
		$this->fillField("Password", "Test-admin-password-2026");
		$this->pressButton("Log in with password");
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$hasApplications = (int)$database->query("select count(*) from UserAdmin where userId = 'administrator'")->fetchColumn() > 0;
		$path = $hasApplications ? "/admin/" : "/admin/applications/";
		$this->expect($this->getSession()->wait(5000,
			"location.pathname === " . json_encode($path)
			. " && document.readyState === 'complete' && document.querySelector('admin-sidebar') !== null"),
			"Administrator sign-in must finish on its account's landing page.");
	}

	#[Then('the provider path should be :path')]
	public function providerPath(string $path):void {
		$this->getSession()->wait(5000, "location.pathname === " . json_encode($path));
		$actual = parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH);
		$this->expect($path === $actual, "Expected path $path, got $actual.");
	}

	#[Then('the account setup should show :complete of :total complete')]
	public function setupProgress(int $complete, int $total):void {
		$progress = $this->getSession()->getPage()->find('css', '.setup-card progress');
		$this->expect($progress !== null, "The account setup progress meter is missing.");
		$this->expect((string)$complete === $progress->getAttribute('value'),
			"Expected $complete completed steps, got " . $progress->getAttribute('value'));
		$this->expect((string)$total === $progress->getAttribute('max'), "Unexpected setup step total.");
	}

	#[Then('the first application note should be highlighted')]
	public function highlightedNote():void {

		$this->expect(parse_url($this->getSession()->getCurrentUrl(), PHP_URL_FRAGMENT) === 'first-application',
			'The redirect did not target the first application note.');
		$this->assertSession()->elementExists('css', '#first-application.onboarding-note');
	}

	#[When('I follow the administration link :link')]
	public function followAdminLink(string $link):void {
		$this->openSidebar();
		$this->getSession()->getPage()->find('css', 'nav[aria-label="Administration"]')->clickLink($link);
	}

	#[When('I press the sidebar logout button')]
	public function logout():void {
		$this->openSidebar();
		$this->getSession()->getPage()->find('css', 'form[data-admin-logout]')->pressButton('Log out');
	}

	#[When('I continue account setup')]
	public function continueSetup():void {
		$this->openSidebar();
		$this->clickLink("Continue setup");
	}

	private function openSidebar():void {
		$menu = $this->getSession()->getPage()->find('css', '.sidebar-menu');
		if(!$menu->hasAttribute('open') && $menu->find('css', 'summary')->isVisible()) {
			$menu->find('css', 'summary')->click();
		}
	}

	#[Then('the setup guide should contain :count steps')]
	public function guideCount(int $count):void {
		$this->assertSession()->elementsCount('css', 'setup-guide details', $count);
	}

	#[Then('only setup step :step should be open')]
	public function currentStep(int $step):void {
		$this->assertSession()->elementsCount('css', 'setup-guide details[open]', 1);
		$this->assertSession()->elementExists('css', "setup-guide details[open][data-step='$step']");
	}

	#[When('I open setup step :step')]
	public function openStep(int $step):void {
		$this->getSession()->getPage()->find('css', "setup-guide details[data-step='$step'] summary")->click();
	}
	#[Then('I should see :text in the application switcher')]
	public function applicationSwitcherText(string $text):void {
		$select = $this->assertSession()->elementExists('css', 'application-switcher select');
		$this->expect($select->isVisible(), 'The application switcher should remain visible.');
		$this->assertSession()->elementTextContains('css', 'application-switcher select', $text);
	}

	#[Then('there should be :count entry in the application switcher')]
	#[Then('there should be :count entries in the application switcher')]
	public function applicationSwitcherEntryCount(int $count):void {
		$this->assertSession()->elementsCount('css', 'application-switcher select option', $count);
	}

	#[Then('the application :name should be saved for the fixture administrator')]
	public function savedApplication(string $name):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select count(*) from Application join UserAdmin on Application.id = UserAdmin.applicationId where UserAdmin.userId = 'administrator' and Application.name = ?");
		$query->execute([$name]);
		$this->expect((int)$query->fetchColumn() === 1, 'The application and admin assignment were not persisted.');
	}

	#[Then('the fixture administrator should have :count saved applications')]
	public function savedApplicationCount(int $count):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$actual = $database->query("select count(*) from UserAdmin where userId = 'administrator'")->fetchColumn();
		$this->expect((int)$actual === $count, 'Unexpected number of saved applications.');
	}

	#[Then('the application switcher should select :name')]
	public function selectedApplication(string $name):void {
		$this->getSession()->wait(5000,
			"document.querySelector('.sidebar-header option[selected]')?.textContent === " . json_encode($name));
		$select = $this->assertSession()->elementExists('css', '.sidebar-header select');
		$selected = $select->find('css', 'option[selected]');
		$this->expect($selected?->getText() === $name,
			'Expected application ' . $name . ', selected ' . ($selected?->getText() ?? '(none)')
			. '. Options: ' . $select->getHtml());
	}

	#[Then('no application name markup should be interpreted as HTML')]
	public function literalApplicationName():void {
		$this->assertSession()->elementNotExists('css', '.application-heading strong');
	}

	#[When('I switch to application :name')]
	public function switchApplication(string $name):void {
		$select = $this->assertSession()->elementExists('css', '.sidebar-header select');
		$select->selectOption($name);
		$this->getSession()->getPage()->find('css', '.sidebar-header')->pressButton('Apply scope');
	}

	private function expect(bool $condition, string $message):void {
		if(!$condition) throw new ExpectationException($message, $this->getSession()->getDriver());
	}

	#[Then('action :action should be audited for the fixture administrator')]
	public function auditedAction(string $action):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select detail from Audit where action = ? and userId = 'administrator'");
		$query->execute([$action]);
		$row = $query->fetch(PDO::FETCH_ASSOC);
		$this->expect($row !== false, "Missing administrator audit record for $action.");
		$detail = $row['detail'] === null ? null
			: json_decode($row['detail'], flags: JSON_THROW_ON_ERROR);
		$this->expect($detail === null || (is_object($detail)
			&& !property_exists($detail, "diff") && !property_exists($detail, "context")),
			"Audit details must not contain duplicate envelopes.");
	}

	#[Then('creation of application :name should be audited')]
	public function auditedApplication(string $name):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->query("select detail from Audit where action = 'APPLICATION_CREATED' and userId = 'administrator'");
		$records = $query->fetchAll(PDO::FETCH_COLUMN);
		$this->expect(count($records) === 1, "Expected exactly one creation audit record.");
		$detail = json_decode($records[0], flags: JSON_THROW_ON_ERROR);
		$this->expect(!property_exists($detail, "from"), "Creation should have no previous state.");
		$this->expect($detail->to->name === $name, "Incorrect application name in audit.");
		$query = $database->prepare("select name from Application where id = ?");
		$query->execute([$detail->to->applicationId]);
		$this->expect($query->fetchColumn() === $name, "Audit does not identify the saved application.");
	}

	private ?string $savedDeploymentKey = null;

	#[Then('the breadcrumb should show :parent followed by :current')]
	public function breadcrumb(string $parent, string $current):void {
		$nav = $this->assertSession()->elementExists('css', 'nav[aria-label="Breadcrumb"]');
		$this->expect($nav->find('css', 'a')?->getText() === $parent, 'Incorrect parent breadcrumb.');
		$this->expect($nav->find('css', 'span[aria-current="page"]')?->getText() === $current,
			'Incorrect current breadcrumb.');
	}

	private ?array $applicationHeadingLayout = null;

	private function applicationHeadingLayout():array {
		$this->getSession()->wait(5000, "document.fonts.status === 'loaded'");
		return $this->getSession()->evaluateScript("(() => {
			const link = document.querySelector('.application-heading a');
			const rect = link.getBoundingClientRect();
			const style = getComputedStyle(link);
			return {x:rect.left + scrollX, y:rect.top + scrollY, width:rect.width, height:rect.height,
				font:style.fontFamily, size:style.fontSize, weight:style.fontWeight, lineHeight:style.lineHeight};
		})()");
	}

	#[When('I record the Applications heading at a viewport width of :width')]
	public function recordApplicationHeading(int $width):void {
		$this->getSession()->resizeWindow($width, 1000);
		$this->applicationHeadingLayout = $this->applicationHeadingLayout();
	}

	#[Then('the Applications heading should keep its position and style')]
	public function consistentApplicationHeading():void {
		$actual = $this->applicationHeadingLayout();
		$this->expect($this->applicationHeadingLayout !== null, 'Missing reference heading layout.');
		foreach($actual as $key => $value) {
			$expected = $this->applicationHeadingLayout[$key];
			$this->expect(is_numeric($value) ? abs($value - $expected) < 0.5 : $value === $expected,
				"Application heading $key changed: expected $expected, got $value.");
		}
	}

	#[When('I follow :text in the breadcrumb')]
	public function followBreadcrumb(string $text):void {
		$nav = $this->assertSession()->elementExists('css', 'nav[aria-label="Breadcrumb"]');
		$link = $nav->findLink($text);
		$this->expect($link !== null, 'Missing breadcrumb link.');
		$path = parse_url($link->getAttribute('href'), PHP_URL_PATH);
		$link->click();
		$this->getSession()->wait(5000, 'location.pathname === ' . json_encode($path));
	}
	private ?string $deletedDeploymentId = null;
	private ?string $deletedDeploymentKey = null;

	#[When('I request deletion of deployment :summary')]
	public function deleteDeployment(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$fields = $details->findAll('css', 'input[readonly]');
		$this->deletedDeploymentId = $fields[0]->getValue();
		$this->deletedDeploymentKey = $this->deploymentKey($this->deletedDeploymentId);
		$details->pressButton('Delete deployment');
		$this->getSession()->wait(5000,
			"document.querySelector('#delete-dialog[open], #delete-confirmation:not([hidden])') !== null");
	}

	#[Then('the deleted deployment should have an audit snapshot without its API key')]
	public function auditedDeletedDeployment():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select detail from Audit where action = 'DEPLOYMENT_DELETED' and userId = 'administrator'");
		$query->execute();
		$records = $query->fetchAll(PDO::FETCH_COLUMN);
		$this->expect(count($records) === 1, "Expected exactly one deployment deletion audit.");
		$detail = json_decode($records[0], flags: JSON_THROW_ON_ERROR);
		$this->expect($detail->from->id === $this->deletedDeploymentId && !property_exists($detail, "to"),
			"Missing deleted deployment details.");
		$this->expect($detail->applicationId === 'managed', "Incorrect application scope.");
		$this->expect($this->deletedDeploymentKey !== null
			&& !str_contains($records[0], $this->deletedDeploymentKey), "API key leaked into audit.");
		$this->expect(!property_exists($detail->from, "secret") && !property_exists($detail->from, "apiKey"),
			"Credentials must be omitted.");
		$query = $database->prepare("select count(*) from ApplicationDeployment where id = ?");
		$query->execute([$this->deletedDeploymentId]);
		$this->expect((int)$query->fetchColumn() === 0, "Deployment was not deleted.");
	}

	#[Then('the production deployment should be unassigned and its removal audited')]
	public function clearedProductionDeployment():void {
		$this->expect($this->getSession()->getPage()->findField('Production deployment')->getValue() === '',
			"Production selection was not cleared.");
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$this->expect($database->query("select productionApplicationDeploymentId from Application where id = 'managed'")->fetchColumn() === null,
			"Stored production selection was not cleared.");
		$records = $database->query("select detail from Audit where action = 'APPLICATION_UPDATED' and userId = 'administrator'")->fetchAll(PDO::FETCH_COLUMN);
		foreach($records as $record) {
			$detail = json_decode($record);
			$change = $detail->from->productionApplicationDeploymentId ?? null;
			if($change === $this->deletedDeploymentId && $detail->to->productionApplicationDeploymentId === null) return;
		}
		throw new RuntimeException("Missing production-selection removal audit.");
	}

	#[Then("the deployment's dependent :table record should remain")]
	public function dependentDeploymentRecord(string $table):void {
		if(!in_array($table, ['User', 'Email'], true)) throw new InvalidArgumentException('Unknown table.');
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$column = $table === 'User' ? 'applicationDeploymentId' : 'deploymentId';
		$query = $database->prepare("select count(*) from $table where $column = ?");
		$query->execute([$this->deletedDeploymentId]);
		$this->expect((int)$query->fetchColumn() === 1, "Dependent record was removed.");
		$this->expect((int)$database->query("select count(*) from Audit where action = 'DEPLOYMENT_DELETED'")->fetchColumn() === 0,
			"A rejected deletion must not be recorded as successful.");
	}

	#[When('I submit invalid application data :case')]
	public function submitInvalidApplicationData(string $case):void {
		$this->getSession()->executeScript('(() => {
			const input = document.querySelector("application-create input[name=name]");
			const form = input.form;
			const kind = ' . json_encode($case) . ';
			input.value = kind === "long" ? "x".repeat(129) : "Application";
			if(kind === "missing") input.removeAttribute("name");
			if(kind === "array") input.name = "name[]";
			form.noValidate = true;
			form.requestSubmit(form.querySelector("button[type=submit]"));
		})()');
		$this->getSession()->wait(5000,
			'document.querySelector("application-create [role=alert]:not([hidden])") !== null');
	}

	#[Then('application settings should fit a viewport of :width by :height')]
	#[Then('the applications table should fit a viewport of :width by :height')]
	public function settingsViewport(int $width, int $height):void {
		$this->getSession()->resizeWindow($width, $height);
		$this->expect($this->getSession()->evaluateScript(
			'document.documentElement.scrollWidth <= document.documentElement.clientWidth'),
			"Application settings overflow at $width pixels.");
		if($directory = getenv("UI_SCREENSHOT_DIR")) {
			if(!is_dir($directory)) mkdir($directory, 0777, true);
			$prefix = $this->getSession()->getPage()->find('css', 'application-list')
				? 'applications-list' : 'application-settings';
			$this->saveScreenshot("$prefix-$width.png", $directory);
		}
	}

	#[Then('the applications table should contain:')]
	public function applicationsTable(\Behat\Gherkin\Node\TableNode $table):void {
		$this->getSession()->resizeWindow(1440, 1000);
		$actual = [];
		$headings = $this->getSession()->getPage()->findAll('css', 'application-list thead th');
		$actual[] = array_map(fn($heading) => $heading->getText(), $headings);
		foreach($this->getSession()->getPage()->findAll('css', 'application-list tbody tr') as $row) {
			$actual[] = array_map(fn($cell) => $cell->getText(), $row->findAll('css', 'td'));
		}
		$this->expect($actual === $table->getRows(), 'Unexpected applications table: ' . json_encode($actual));
	}

	#[When('I follow :text in the applications table')]
	public function followApplicationTableLink(string $text):void {
		$table = $this->assertSession()->elementExists('css', 'application-list table');
		$link = $table->findLink($text);
		$this->expect($link !== null, 'Missing application settings link.');
		$path = parse_url($link->getAttribute('href'), PHP_URL_PATH);
		$link->click();
		$this->getSession()->wait(5000, 'location.pathname === ' . json_encode($path));
	}

	#[Then('the applications list should precede the create panel and adapt to the screen')]
	public function applicationListLayout():void {
		$this->expect($this->getSession()->evaluateScript(
			'Boolean(document.querySelector("application-list").compareDocumentPosition(document.querySelector("application-create")) & Node.DOCUMENT_POSITION_FOLLOWING)'),
			'The list must precede the create panel in the HTML.');
		foreach([1440, 390] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$sideBySide = $width === 1440 ? 'true' : 'false';
			$this->expect($this->getSession()->evaluateScript('(() => {
				const list = document.querySelector("application-list section").getBoundingClientRect();
				const create = document.querySelector("application-create section").getBoundingClientRect();
				return ' . $sideBySide . '
					? create.left >= list.right && Math.abs(create.top - list.top) < 2
					: create.top >= list.bottom;
			})()'), "Unexpected application panel layout at $width pixels.");
		}
	}

	#[Then('every application table cell should link to its row target')]
	public function clickableApplicationCells():void {
		$table = $this->assertSession()->elementExists('css', 'application-list table.table-link');
		$this->expect(count($table->findAll('css', 'thead a')) === 0, 'Column headings should not be linked.');
		foreach($table->findAll('css', 'tbody tr') as $row) {
			$source = $row->find('css', 'a[data-table-link]');
			$this->expect($source !== null, 'Missing row source link.');
			$target = $source->getAttribute('href');
			$this->expect($source->getAttribute('tabindex') === null, 'Original link must stay keyboard accessible.');
			$this->expect($row->getAttribute('data-clickable-href') === null, 'Row target must not be duplicated.');
			$this->expect($target !== null && $target !== '', 'Missing row target.');
			foreach($row->findAll('css', 'td') as $cell) {
				$links = $cell->findAll('css', 'a');
				$this->expect(count($links) === 1 && $links[0]->getAttribute('href') === $target,
					'Every cell must have exactly one link to its application.');
				if(!$links[0]->hasAttribute('data-table-link')) {
					$this->expect($links[0]->getAttribute('tabindex') === '-1', 'Additional links must skip tab order.');
				}
			}
		}
		$this->expect(count($table->findAll('css', 'a a')) === 0, 'Nested links are invalid.');
	}

	#[When('I follow the :column cell for application :name')]
	public function followApplicationCell(string $column, string $name):void {
		foreach($this->getSession()->getPage()->findAll('css', 'application-list tbody tr') as $row) {
			if($row->find('css', 'td[data-label="Name"]')->getText() !== $name) continue;
			$cell = $row->find('css', 'td[data-label="' . $column . '"]');
			$this->expect($cell !== null, 'Missing application cell.');
			$path = $row->find('css', 'a[data-table-link]')->getAttribute('href');
			$cell->click();
			$this->getSession()->wait(5000, 'location.pathname === ' . json_encode($path));
			return;
		}
		throw new RuntimeException('Missing application row.');
	}

	#[Then('I should be on the settings page for application :name')]
	public function applicationSettingsPage(string $name):void {
		$this->expect($this->getSession()->wait(5000,
			'document.querySelector("application-settings form") !== null'),
			"Creating an application must reach its settings form.");
		$actualName = $this->assertSession()->elementExists("css", 'application-settings input[name="name"]')->getValue();
		$this->expect($actualName === $name, "Expected application '$name', received '$actualName'.");
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select Application.id from Application join UserAdmin on UserAdmin.applicationId = Application.id where Application.name = ? and UserAdmin.userId = 'administrator'");
		$query->execute([$name]);
		$identifier = $query->fetchColumn();
		$this->expect(is_string($identifier), "Application was not persisted.");
		$path = "/admin/applications/$identifier/";
		$this->getSession()->wait(5000, "location.pathname === " . json_encode($path));
		$this->assertPageAddress($path);
	}

	#[Then('the application settings should show its read-only ID and name :name')]
	public function applicationSettingsFields(string $name):void {
		$form = $this->assertSession()->elementExists('css', 'application-settings form');
		$identifier = $form->find('css', 'input[readonly]');
		$this->expect($identifier !== null && $identifier->getValue() !== "", "Missing read-only application ID.");
		$this->expect($form->findField('Name')->getValue() === $name, "Incorrect application name.");
	}

	#[Then('the production deployment select should have :count options')]
	public function productionOptionCount(int $count):void {
		$this->assertSession()->elementsCount('css', 'application-settings select option', $count);
	}

	#[Then('there should be no open deployment disclosures')]
	public function noOpenDeploymentDisclosures():void {
		$this->assertSession()->elementsCount('css', 'application-deployments details[open]', 0);
	}

	#[Then('the create deployment disclosure should be compact with a plus icon')]
	public function compactDeploymentDisclosure():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const details = document.querySelector(".deployment-list > [data-new=\\"1\\"] > details");
			const summary = details.querySelector("summary");
			const icon = summary.querySelector("[data-icon=plus]");
			const rect = details.getBoundingClientRect();
			const parent = details.parentElement.getBoundingClientRect();
			return getComputedStyle(details).display === "inline-block"
				&& Math.abs((rect.left + rect.right) - (parent.left + parent.right)) < 2
				&& details.querySelector("button[value=save-deployment]").classList.contains("primary")
				&& !document.querySelector("application-settings button").classList.contains("primary")
				&& !document.querySelector(".deployment-list > :not([data-new=\\"1\\"]) button[value=save-deployment]").classList.contains("primary")
				&& getComputedStyle(summary).backgroundColor === getComputedStyle(details.querySelector("button[value=save-deployment]")).backgroundColor
				&& details.getBoundingClientRect().width < details.parentElement.getBoundingClientRect().width
				&& getComputedStyle(summary).listStyleType === "none"
				&& getComputedStyle(icon).display !== "none"
				&& getComputedStyle(icon, "::before").maskImage.includes("plus.svg")
				&& getComputedStyle(document.querySelector(".deployment-list > :not([data-new=\\"1\\"]) summary")).display === "list-item";
		})()'), 'Create deployment should have a compact plus disclosure; saved deployments keep their markers.');
	}

	#[When('I open deployment :summary')]
	public function openDeployment(string $summary):void {
		$this->deploymentDetails($summary);
	}

	#[When('I close deployment :summary')]
	public function closeDeployment(string $summary):void {
		$this->deploymentDetails($summary)->find('css', 'summary')->click();
	}

	#[Then('only deployment :summary should be open')]
	public function onlyDeploymentOpen(string $summary):void {
		$this->assertSession()->elementsCount('css', 'application-deployments details[open]', 1);
		$open = $this->assertSession()->elementExists('css', 'application-deployments details[open]');
		$this->expect($open->find('css', '.deployment-title')->getText() === $summary,
			'Unexpected open deployment.');
	}

	#[Then('application form actions should reserve an input label and gap above their buttons')]
	public function applicationFormActionSpacing():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const form = document.querySelector("application-settings form");
			const label = form.querySelector("label");
			const actions = form.querySelector(".actions");
			const expected = label.querySelector("span").getBoundingClientRect().height
				+ parseFloat(getComputedStyle(label).rowGap);
			return [...form.querySelectorAll("button")].every(button => actions.contains(button))
				&& Math.abs(parseFloat(getComputedStyle(actions).paddingBlockStart) - expected) < 2;
		})()'), 'Action container spacing must match the input label line and field gap.');
	}

	#[Then('application settings should precede deployments and adapt to the screen')]
	public function applicationSettingsLayout():void {
		$this->expect($this->getSession()->evaluateScript(
			'Boolean(document.querySelector("application-settings").compareDocumentPosition(document.querySelector("application-deployments")) & Node.DOCUMENT_POSITION_FOLLOWING)'),
			'Settings must precede deployments in the HTML.');
		foreach([1440, 390, 280] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$sideBySide = $width === 1440 ? 'true' : 'false';
			$this->expect($this->getSession()->evaluateScript('(() => {
				const settings = document.querySelector("application-settings section").getBoundingClientRect();
				const deployments = document.querySelector("application-deployments section").getBoundingClientRect();
				return ' . $sideBySide . '
					? deployments.left >= settings.right && Math.abs(deployments.top - settings.top) < 2
					: deployments.top >= settings.bottom;
			})()'), "Unexpected settings panel layout at $width pixels.");
			$this->settingsViewport($width, 1000);
		}
	}

	#[Then('there should be :count deployment expander')]
	#[Then('there should be :count deployment expanders')]
	public function deploymentPanelCount(int $count):void {
		$this->assertSession()->elementsCount('css', 'application-deployments ul > li > details', $count);
	}

	#[Then('the new deployment form should retain hidden read-only ID and API key fields')]
	public function emptyDeploymentFields():void {
		$fields = $this->getSession()->getPage()->findAll('css', 'application-deployments li[data-new="1"] input[readonly]');
		$this->expect(count($fields) === 2, "Both read-only fields must remain in the HTML.");
		foreach($fields as $field) {
			$this->expect(!$field->isVisible() && $field->getValue() === "", "New deployment fields should be hidden and empty.");
		}
	}

	private function deploymentDetails(string $summary):\Behat\Mink\Element\NodeElement {
		foreach($this->getSession()->getPage()->findAll('css', 'application-deployments details') as $details) {
			if($details->find('css', '.deployment-title')->getText() !== $summary) continue;
			if(!$details->hasAttribute('open')) $details->find('css', 'summary')->click();
			return $details;
		}
		throw new RuntimeException("Missing deployment expander: $summary");
	}

	#[Then('deployment :summary should retain:')]
	public function retainedDeploymentValues(string $summary, \Behat\Gherkin\Node\TableNode $table):void {
		$details = $this->deploymentDetails($summary);

		foreach($table->getRows() as [$field, $value]) {
			$this->expect($details->findField($field)->getValue() === $value,
				"The saved $field value must be rendered in the deployment form.");
		}
	}

	#[When('I complete the :summary deployment form:')]
	public function fillDeployment(string $summary, \Behat\Gherkin\Node\TableNode $table):void {
		$details = $this->deploymentDetails($summary);
		foreach($table->getRows() as [$field, $value]) $details->fillField($field, $value);
	}

	private int $deploymentPostCount = 0;

	private function deploymentPosts():int {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		return (int)$database->query("select count(*) from Audit where action = 'REQUEST_RECEIVED' and json_extract(detail, '$.method') = 'POST'")->fetchColumn();
	}

	#[When('I attempt to submit deployment :summary')]
	public function attemptDeploymentSubmission(string $summary):void {
		$this->deploymentPostCount = $this->deploymentPosts();
		$this->deploymentDetails($summary)->find("css", "button[value=save-deployment]")->press();
	}

	#[Then('the deployment field :name should have a native pattern validation error')]
	public function nativeDeploymentValidation(string $name):void {
		$selector = json_encode('application-deployments details[open] [name="' . $name . '"]');
		$this->expect($this->getSession()->evaluateScript("(() => {
			const field = document.querySelector($selector);
			return field.validity.patternMismatch && !field.validity.valid && document.activeElement === field;
		})()"), "The browser must prevent submission and focus the invalid field.");
	}

	#[Then('no deployment form request should have reached the server')]
	public function noDeploymentRequest():void {
		$this->expect($this->deploymentPosts() === $this->deploymentPostCount, "Invalid browser input must not reach the server.");
	}

	#[When('I submit deployment :summary without browser validation')]
	public function bypassDeploymentBrowserValidation(string $summary):void {
		$this->deploymentDetails($summary);
		$this->getSession()->evaluateScript('document.querySelector("application-deployments details[open] form").noValidate = true');
		$this->saveDeployment($summary);
	}

	#[When('I press Enter in the deployment name for :summary')]
	public function submitDeploymentWithEnter(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$field = $details->findField("Deployment name");
		$field->focus();
		$field->keyPress(13);
		$this->getSession()->wait(5000, "document.querySelector('application-deployments [role=status]:not([hidden])') !== null");
	}

	#[When('I save deployment :summary')]
	public function saveDeployment(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$details->find('css', 'button[value=save-deployment]')->press();
		$this->getSession()->wait(5000, "document.querySelector('application-deployments [role=status]:not([hidden]), application-deployments details[open] [role=alert]:not([hidden])') !== null");
	}

	#[Then('the open deployment form :summary should show :message and retain:')]
	public function rejectedDeploymentForm(string $summary, string $message, \Behat\Gherkin\Node\TableNode $values):void {
		$details = $this->assertSession()->elementExists("css", "application-deployments details[open]");
		$this->expect($details->find("css", ".deployment-title")->getText() === $summary, "The submitted deployment must remain open.");
		$alert = $details->find("css", "[role=alert]");
		$this->expect($alert !== null && $alert->isVisible() && $alert->getText() === $message,
			"The validation error must be visible inside the submitted form.");
		$this->expect($this->getSession()->evaluateScript('(() => {
			const alert = document.querySelector("application-deployments details[open] [role=alert]:not([hidden])");
			const bounds = alert.getBoundingClientRect();
			return document.activeElement === alert && bounds.top >= 0 && bounds.bottom <= innerHeight;
		})()'), "The error must receive focus and be visible in the viewport.");

		foreach($values->getRows() as [$field, $value]) {
			$this->expect($details->findField($field)->getValue() === $value, "The $field value must be retained.");
		}
	}

	#[Then('deployment :summary should have a saved read-only ID and API key')]
	public function savedDeploymentFields(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$fields = $details->findAll('css', 'input[readonly]');
		$this->expect(count($fields) === 2, "Missing read-only deployment fields.");
		foreach($fields as $field) $this->expect($field->isVisible() && $field->getValue() !== "", "Deployment fields should be visible and populated.");
		$this->savedDeploymentKey = $this->deploymentKey($fields[0]->getValue());
		$this->obscuredDeploymentKey($summary);
	}

	#[Then('deployment :summary should keep the same API key')]
	public function stableDeploymentKey(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$fields = $details->findAll('css', 'input[readonly]');
		$this->expect($this->savedDeploymentKey !== null && $this->deploymentKey($fields[0]->getValue()) === $this->savedDeploymentKey, "Editing must not rotate the API key.");
		$this->obscuredDeploymentKey($summary);
	}

	#[Then('production should use deployment :name')]
	public function productionDeployment(string $name):void {
		$select = $this->getSession()->getPage()->findField('Production deployment');
		$this->expect($select->find('css', 'option[selected]')?->getText() === $name, "Incorrect production option.");
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select title from ApplicationDeployment where id = (select productionApplicationDeploymentId from Application where id = ApplicationDeployment.applicationId) and id = ?");
		$query->execute([$select->getValue()]);
		$this->expect($query->fetchColumn() === $name, "Production selection was not persisted.");
	}

	private function deletionConfirmation():\Behat\Mink\Element\NodeElement {
		return $this->assertSession()->elementExists('css', '#delete-dialog[open], #delete-confirmation:not([hidden])');
	}

	#[Then('I should see :message in the deletion confirmation')]
	public function deletionConfirmationMessage(string $message):void {
		$this->expect($this->deletionConfirmation()->find('css', 'h2')->getText() === $message,
			'Incorrect deletion confirmation message.');
		$this->noDeploymentDeleted();
		if(getenv('BEHAT_JAVASCRIPT') === '1') {
			$this->expect($this->getSession()->evaluateScript("document.querySelector('#delete-dialog').matches(':modal')"),
				'Deletion should use a native modal dialog.');
			$this->expect($this->getSession()->evaluateScript('document.activeElement.value') === 'cancel',
				'Cancel should have initial focus.');
		}
		if($directory = getenv('UI_SCREENSHOT_DIR')) {
			if(!is_dir($directory)) mkdir($directory, 0777, true);
			$this->saveScreenshot('delete-confirmation.png', $directory);
		}
	}

	#[When('I dismiss the deletion confirmation')]
	public function dismissDeletion():void {
		$confirmation = $this->deletionConfirmation();
		if($confirmation->getTagName() === 'dialog') {
			$cancel = $confirmation->findButton('Cancel');
			$cancel->keyDown(27);
			$cancel->keyUp(27);
		}
		else $confirmation->clickLink('Cancel');
		$this->getSession()->wait(5000, "document.querySelector('#delete-dialog[open], #delete-confirmation:not([hidden])') === null");
	}

	#[When('I confirm deletion')]
	public function confirmDeletion():void {
		$this->deletionConfirmation()->pressButton('Confirm delete');
		$this->getSession()->wait(5000,
			"document.querySelector('#all-users [role=status]')?.textContent.trim().length > 0 || document.querySelector('application-settings [role=status]')?.textContent === 'Deployment deleted.' || document.querySelector('application-settings [role=alert]:not([hidden])') !== null");
	}

	#[Then('the deletion confirmation name should not contain HTML elements')]
	public function deletionConfirmationPlainText():void {
		$this->expect($this->deletionConfirmation()->find('css', 'h2 *') === null,
			'The deletion name must be escaped text.');
	}

	#[When('I cancel deletion')]
	public function cancelDeletion():void {
		$confirmation = $this->deletionConfirmation();
		if($confirmation->getTagName() === 'dialog') $confirmation->pressButton('Cancel');
		else $confirmation->clickLink('Cancel');
		$this->getSession()->wait(5000, "document.querySelector('#delete-dialog[open], #delete-confirmation:not([hidden])') === null");
	}

	#[Then('no deployment should have been deleted')]
	public function noDeploymentDeleted():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select count(*) from ApplicationDeployment where id = ?");
		$query->execute([$this->deletedDeploymentId]);
		$this->expect((int)$query->fetchColumn() === 1, 'Deployment was deleted before confirmation.');
		$this->expect((int)$database->query("select count(*) from Audit where action = 'DEPLOYMENT_DELETED'")->fetchColumn() === 0,
			'Unconfirmed deletion must not be audited as successful.');
	}

}
