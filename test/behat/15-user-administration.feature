Feature: Administrators manage real deployment users
  Background:
    Given the database is in the "user_administration" state
    And I sign in as the fixture administrator
    When I go to "/admin/users/"

  Scenario: User search and login failures are scoped to the selected application
    Then I should see "bo•@ex••••••••••" in the "#login-failures" element
    And I should not see "al•••@ex••••••••••" in the "#login-failures" element
    And I should not see "private@example.test"
    And I should see "al•••@ex••••••••••" in the "#all-users" element
    When I search users for "b*b@*.test"
    Then I should see "bo•@ex••••••••••" in the "#all-users" element
    And I should not see "al•••@ex••••••••••" in the "#all-users" element
    When I search users for "missing"
    Then I should see "No users match these filters."

  Scenario: An administrator creates a password user without sending email
    When I fill in "email" with "created@example.test"
    And I enable the new user password field
    And I fill in "Password" with "A-manually-set-password"
    And I uncheck "Email user instructions for logging in"
    And I submit the Users page with "Add user"
    Then I should see "User created."
    And user "created@example.test" should have password "A-manually-set-password" and no queued instructions
    And action "USER_CREATED" should be audited for the fixture administrator
    When I reload the page
    Then I should see "cr•••••@ex••••••••••" in the "#all-users" element
    When I log in to deployment "managed-development" as "created@example.test" with password "A-manually-set-password"
    Then I should see "You have logged in successfully."

  Scenario: Email-only users receive instructions rendered from Markdown
    When I fill in "email" with "email-only@example.test"
    And I submit the Users page with "Add user"
    Then I should see "User created. Login instructions have been queued."
    And user "email-only@example.test" should have email login instructions and no password
    And action "EMAIL_SCHEDULED" should be audited for the fixture administrator

  Scenario: Duplicate users are rejected without discarding the entered email
    When I fill in "email" with "alice@example.test"
    And I submit the Users page with "Add user"
    Then I should see "A user with this email address already exists in this deployment."
    And the "email" field should contain "alice@example.test"
    And action "USER_CREATION_REJECTED" should be audited for the fixture administrator

  Scenario: Password length is enforced in both the browser and the server
    When I fill in "email" with "invalid@example.test"
    And I enable the new user password field
    And I fill in "Password" with "short"
    And I attempt to add the user
    Then the user password should fail native validation without a request
    When I add the user without browser validation
    Then I should see "Password:"
    And the "email" field should contain "invalid@example.test"
    And user "invalid@example.test" should not exist
    And action "USER_CREATION_REJECTED" should be audited for the fixture administrator

  Scenario: User controls fit desktop and mobile screens
    Then the Users page should fit narrow and wide screens

  Scenario: Email addresses require sudo and all remain visible during the sudo period
    Then stored user emails should be obscured without leaking their values
    And email view icons should appear on hover and keyboard focus
    When I view a protected user email
    Then I should be on "/login/sudo/"
    When I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then all stored user emails should be visible
    And action "SUDO_FIELD_ACCESSED" should be audited for the fixture administrator
    When I reload the page
    Then all stored user emails should be visible
    And visible email controls should copy instead of viewing
    When I disable sudo mode
    Then stored user emails should be obscured without leaking their values

  Scenario: Email addresses become obscured again when sudo expires
    When I view a protected user email
    And I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then all stored user emails should be visible
    When the sudo period expires
    Then stored user emails should be obscured without leaking their values

  Scenario: Visible emails can be copied on HTTP development origins without the Clipboard API
    When I view a protected user email
    And I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then all stored user emails should be visible
    Given the Clipboard API is unavailable
    Then visible email controls should copy instead of viewing

  Scenario: User search adapts to its container and keeps its HTTP fallback
    Then user search should adapt to narrow and wide containers

  Scenario: Changing the deployment filter refreshes user results
    When I filter users by deployment "Staging"
    Then I should see "st•••••@ex••••••••••" in the "#all-users" element
    And I should not see "al•••@ex••••••••••" in the "#all-users" element
    And the deployment filter should show "Staging" with only this application's deployments
    When I search users for "st*@*.test"
    Then I should see "st•••••@ex••••••••••" in the "#all-users" element
    And the deployment filter should show "Staging" with only this application's deployments
    When I filter users by deployment "Development"
    Then I should see "No users match these filters."
    When I search users for "*"
    Then I should see "bo•@ex••••••••••" in the "#all-users" element
    And I should not see "st•••••@ex••••••••••" in the "#all-users" element
    When I filter users by deployment "All deployments"
    Then I should see "st•••••@ex••••••••••" in the "#all-users" element
