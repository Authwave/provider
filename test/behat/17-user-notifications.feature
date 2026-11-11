Feature: Administrator-created users reach the client immediately
  Background:
    Given the database is in the "user_administration" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"

  Scenario: Creating a user sends encrypted query parameters to the existing client login path
    When I go to "/admin/users/"
    And I fill in "email" with "new-client-user@example.test"
    And I uncheck "Email user instructions for logging in"
    And I submit the Users page with "Add user"
    Then I should see "User created."
    And the client should have received user "new-client-user@example.test" 1 time
    And action "USER_NOTIFICATION_DELIVERED" should be audited for the fixture administrator
    And user "new-client-user@example.test" should not have a Redeliver button

  Scenario: Failed notifications can be redelivered without creating another user
    Given the client responds to notifications with HTTP 503
    When I go to "/admin/users/"
    And I fill in "email" with "retry@example.test"
    And I uncheck "Email user instructions for logging in"
    And I submit the Users page with "Add user"
    Then I should see "User created. Client notification could not be confirmed."
    And the client should have received user "retry@example.test" 1 time
    And action "USER_NOTIFICATION_FAILED" should be audited for the fixture administrator
    And user "retry@example.test" should have a synchronisation error indicator
    Given the client responds to notifications with HTTP 204
    When I redeliver the client notification for "retry@example.test"
    Then the client should have received user "retry@example.test" 2 times
    And action "USER_NOTIFICATION_DELIVERED" should be audited for the fixture administrator
    And user "retry@example.test" should not have a Redeliver button

  Scenario: An older client returning its HTML login page is not an acknowledgement
    Given the client responds to notifications with HTTP 200
    When I go to "/admin/users/"
    And I fill in "email" with "old-client@example.test"
    And I uncheck "Email user instructions for logging in"
    And I submit the Users page with "Add user"
    Then I should see "Client notification could not be confirmed."
    And action "USER_NOTIFICATION_FAILED" should be audited for the fixture administrator

  Scenario: Successful client logins and unattempted notifications do not need redelivery
    When I go to "/admin/users/"
    Then user "alice@example.test" should not have a Redeliver button
    And user "bob@example.test" should not have a Redeliver button
    And the client should have received user "alice@example.test" 0 times

  Scenario: Deletion waits for confirmation and removes the user from both applications
    When I go to "/admin/users/"
    And I fill in "email" with "delete-me@example.test"
    And I uncheck "Email user instructions for logging in"
    And I submit the Users page with "Add user"
    Then user "delete-me@example.test" should exist in the provider and client
    When I open user details for "delete-me@example.test"
    Then I should see "No successful logins yet."
    When I delete the user from their details
    Then I should see "Confirm deleting delete-me@example.test" in the user deletion confirmation
    When I dismiss the deletion confirmation
    Then user "delete-me@example.test" should exist in the provider and client
    When I open user details for "delete-me@example.test"
    And I delete the user from their details
    And I confirm deletion
    Then I should see "User deleted."
    And user "delete-me@example.test" should be deleted from the provider and client
    And action "USER_DELETED" should be audited for the fixture administrator

  Scenario: A failed client deletion keeps the user available for a deletion retry
    When I go to "/admin/users/"
    And I fill in "email" with "delete-retry@example.test"
    And I uncheck "Email user instructions for logging in"
    And I submit the Users page with "Add user"
    Given the client responds to notifications with HTTP 503
    When I open user details for "delete-retry@example.test"
    And I delete the user from their details
    And I confirm deletion
    Then I should see "Deletion could not be confirmed. The user has been retained."
    And user "delete-retry@example.test" should exist in the provider and client
    Given the client responds to notifications with HTTP 204
    When I redeliver the client notification for "delete-retry@example.test"
    Then I should see "User deleted."
    And user "delete-retry@example.test" should be deleted from the provider and client

  Scenario: User details show private account information in a responsive popover
    When I go to "/admin/users/"
    And I open user details for "alice@example.test"
    Then the user details should show their login history and deployment
    And the user details should fit mobile and desktop screens

  Scenario: Sudo returns to the requested details email
    When I go to "/admin/users/"
    And I open user details for "alice@example.test"
    And I view the email in user details
    And I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then the details for "alice@example.test" should return with a visible email

  Scenario: Failed deletion is visible even when the user has logged in before
    Given the client responds to notifications with HTTP 503
    When I go to "/admin/users/"
    And I open user details for "alice@example.test"
    And I delete the user from their details
    And I confirm deletion
    Then user "alice@example.test" should have a synchronisation error indicator
    When I open user details for "alice@example.test"
    Then I should see "Deletion was not confirmed. This user has been retained."

  Scenario: Sudo authorisation is followed by a separate delete confirmation
    When I go to "/admin/users/"
    And I fill in "email" with "sudo-delete@example.test"
    And I uncheck "Email user instructions for logging in"
    And I submit the Users page with "Add user"
    And I open user details for "sudo-delete@example.test"
    Then the user delete button should require sudo
    When I request sudo for user deletion
    Then user "sudo-delete@example.test" should exist in the provider and client
    When I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then sudo should return to user details with Delete enabled
    And user "sudo-delete@example.test" should exist in the provider and client
    When I delete the user from their details
    And I confirm deletion
    Then user "sudo-delete@example.test" should be deleted from the provider and client

  Scenario: A crafted confirmed deletion cannot bypass sudo
    When I go to "/admin/users/"
    And I fill in "email" with "protected-delete@example.test"
    And I uncheck "Email user instructions for logging in"
    And I submit the Users page with "Add user"
    And I open user details for "protected-delete@example.test"
    And I bypass the disabled user delete control
    Then user "protected-delete@example.test" should exist in the provider and client
    And the client should have received user "protected-delete@example.test" 1 time

  @javascript
  Scenario: The confirmation clears the revealed email when sudo expires
    When I go to "/admin/users/"
    And I open user details for "alice@example.test"
    And I delete the user from their details
    Then I should see "Confirm deleting alice@example.test" in the user deletion confirmation
    And the user confirmation should close when sudo expires
    And the client should have received user "alice@example.test" 0 times
