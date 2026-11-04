Feature: Password confirmation protects sensitive administrator controls
  Background:
    Given the database is in the "deployment_admin" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"

  Scenario: Viewing a deployment key requires a password and reveals it for one response
    Then deployment "Development" should have an obscured API key without leaking its value
    When I view the API key of deployment "Development"
    Then I should be on "/login/sudo/"
    When I fill in "Password" with "Incorrect-password-2026"
    And I press "Enable sudo mode"
    Then I should see "Your password was not accepted. Please try again."
    And action "SUDO_REJECTED" should be audited for the fixture administrator
    When I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then sudo mode should be enabled
    And I can view the API key of deployment "Development"
    And action "SUDO_AUTHORISED" should be audited for the fixture administrator
    And action "SUDO_FIELD_ACCESSED" should be audited for the fixture administrator
    And the sudo audit trail should contain no credentials
    When I reload the page
    Then deployment "Development" should have an obscured API key without leaking its value
    When I view the API key of deployment "Development"
    Then the API key of deployment "Development" should be visible
    When I disable sudo mode
    Then deployment "Development" should have an obscured API key without leaking its value
    And action "SUDO_DISABLED" should be audited for the fixture administrator
    When I view the API key of deployment "Development"
    Then I should be on "/login/sudo/"

  Scenario: Cancelling password confirmation does not reveal the key
    When I view the API key of deployment "Development"
    And I press "Cancel"
    Then deployment "Development" should have an obscured API key without leaking its value
    And sudo mode should be disabled

  Scenario: Copying uses sudo authorisation and remains available during the sudo period
    When I copy the API key of deployment "Development"
    Then copying the API key should require sudo and copy the saved value

  Scenario: Expiry requires password confirmation again
    When I view the API key of deployment "Development"
    And I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then sudo mode should be enabled
    And I can view the API key of deployment "Development"
    When the sudo period expires
    Then deployment "Development" should have an obscured API key without leaking its value
    When I view the API key of deployment "Development"
    Then I should be on "/login/sudo/"
    And action "SUDO_EXPIRED" should be audited for the fixture administrator

  Scenario: Logging out discards sudo authorisation
    When I view the API key of deployment "Development"
    And I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then sudo mode should be enabled
    When I press the sidebar logout button
    Then I should be on "/logged-out/"
    When I sign in as the fixture administrator
    And I go to "/admin/applications/managed/"
    Then sudo mode should be disabled
    When I view the API key of deployment "Development"
    Then I should be on "/login/sudo/"
