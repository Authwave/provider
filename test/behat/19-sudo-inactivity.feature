Feature: Sudo expires after inactivity rather than a fixed session length
  Background:
    Given the database is in the "deployment_admin" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"
    And I view the API key of deployment "Development"
    And I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then sudo mode should be enabled

  Scenario: Page reloads keep sudo active without further password confirmation
    When I reload shortly before sudo expires
    Then the sudo inactivity deadline should be renewed
    When I reload shortly before sudo expires
    Then the sudo inactivity deadline should be renewed
    And sudo should only have required one password confirmation
    When the sudo period expires
    And I view the API key of deployment "Development"
    Then I should be on "/login/sudo/"

  @javascript
  Scenario: Browser interaction extends sudo without navigating away
    When I type shortly before sudo expires
    Then the sudo inactivity deadline should be renewed
    When I type shortly before sudo expires
    Then the sudo inactivity deadline should be renewed
    And sudo should only have required one password confirmation
    When the sudo period expires
    Then sudo mode should be disabled

  @javascript
  Scenario: Automatic background requests do not keep an unattended page elevated
    When the provider refreshes automatically until sudo expires
    Then sudo mode should be disabled
    When I view the API key of deployment "Development"
    Then I should be on "/login/sudo/"
