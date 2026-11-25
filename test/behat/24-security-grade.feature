Feature: Security letter grade
  Background:
    Given the database is in the "existing_admin" state
    And I sign in as the fixture administrator

  Scenario: The starting C grade improves when a session policy is enforced
    When I go to "/admin/security/"
    Then the security grade should be "C"
    And I should see "No active sessions to assess."
    When I press "Enable sudo mode"
    And I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    And I fill in "Maximum session lifetime (minutes)" with "30"
    And I fill in "Idle timeout (minutes)" with "15"
    And I press "Save session settings"
    Then the security grade should be "B"
    And I should see "Managed clients are required, with timeouts at or below the app defaults."

  Scenario: The grade includes all sessions even when the list filters them out
    Given the application has verified production HTTPS and an older development session
    When I go to "/admin/security/?sessionDeployment=score-prod"
    Then the security grade should be "B"
    And I should see "1 active older-client session cannot be revoked remotely."
    When the older development session expires
    And I go to "/admin/security/?sessionDeployment=score-prod"
    Then the security grade should be "A"
    And I should see "No active sessions to assess."
    And the security panels should follow the section order and stack on mobile
