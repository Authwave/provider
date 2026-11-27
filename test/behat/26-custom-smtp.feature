Feature: Custom SMTP configuration
  Background:
    Given the database is in the "existing_admin" state
    And I sign in as the fixture administrator
    And the selected application has email deliveries

  Scenario: Application SMTP details can be enabled, saved and disabled
    When I go to "/admin/emails/#smtp"
    Then the SMTP details should be "disabled"
    When I check "Use custom SMTP details"
    Then the SMTP details should be "enabled"
    When I fill in "SMTP host" with "smtp.example.test"
    And I fill in "Port" with "587"
    And I fill in "Username" with "smtp-user"
    And I fill in "Password (leave empty to keep saved value)" with "smtp-password"
    And I press "Save SMTP settings"
    Then I should see "SMTP settings saved."
    And the SMTP details should be "enabled"
    And the "SMTP host" field should contain "smtp.example.test"
    When I fill in "SMTP host" with "smtp.changed.test"
    And I press "Save SMTP settings"
    Then I should see "SMTP settings saved."
    And the "SMTP host" field should contain "smtp.changed.test"
    When I uncheck "Use custom SMTP details"
    Then the SMTP details should be "disabled"
    When I press "Save SMTP settings"
    Then I should see "SMTP settings saved."
    And the SMTP details should be "disabled"

  Scenario: Deployment settings are separate from the application default
    When I go to "/admin/emails/#smtp"
    And I select "Security development" from "SMTP configuration"
    And I press "Choose configuration"
    Then the SMTP details should be "disabled"
    When I check "Use custom SMTP details"
    And I fill in "SMTP host" with "smtp.deployment.test"
    And I fill in "Port" with "2525"
    And I fill in "Username" with "deployment-user"
    And I fill in "Password (leave empty to keep saved value)" with "deployment-password"
    And I press "Save SMTP settings"
    Then I should see "SMTP settings saved."
    And the "SMTP host" field should contain "smtp.deployment.test"
    When I select "Application default" from "SMTP configuration"
    And I press "Choose configuration"
    Then the SMTP details should be "disabled"
    When I select "Security development" from "SMTP configuration"
    And I press "Choose configuration"
    Then the SMTP details should be "enabled"
    And the "SMTP host" field should contain "smtp.deployment.test"
