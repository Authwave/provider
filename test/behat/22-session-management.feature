Feature: Application session management
  Background:
    Given the database is in the "production_client" state
    And production client URLs use the test server
    And I sign in as the fixture administrator

  Scenario: No active sessions has a clear empty state
    When I go to "/admin/security/"
    Then I should see "No active sessions"
    And I should see "Authwave administrator sessions are separate."
    And session management should fit narrow and wide screens

  Scenario: Saving a session policy requires sudo and persists the timeouts
    When I go to "/admin/security/"
    And I press "Enable sudo mode"
    Then I should be on "/login/sudo/"
    When I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    And I fill in "Maximum session lifetime (minutes)" with "60"
    And I fill in "Idle timeout (minutes)" with "10"
    And I press "Save session settings"
    Then I should see "Session policy saved."
    When I reload the page
    Then the "maximumLifetimeMinutes" field should contain "60"
    And the "idleTimeoutMinutes" field should contain "10"
    And action "SESSION_POLICY_UPDATED" should be audited for the fixture administrator

  Scenario: Revoking sessions invalidates them at the real client validation endpoint
    When I start a managed client login on the production provider
    And I sign in as the production customer
    Then the managed client session should be "valid"
    When I go to "/admin/security/"
    And I press "Revoke session"
    Then I should be on "/login/sudo/"
    When I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    And I press "Revoke session"
    Then I should see "Sign out this session from Development?"
    And session management should fit narrow and wide screens
    And the managed client session should be "valid"
    When I follow "Cancel"
    Then the managed client session should be "valid"
    When I press "Revoke all managed sessions"
    Then I should see "Sign out 1 managed session"
    When I press "Confirm revocation"
    Then I should see "1 managed session revoked."
    And I should see "No active sessions"
    And the managed client session should be "invalid"
    And action "SESSIONS_REVOKED" should be audited for the fixture administrator

  Scenario: Revoking an individual session requires confirmation
    When I start a managed client login on the production provider
    And I sign in as the production customer
    Then the managed client session should be "valid"
    When I go to "/admin/security/"
    And I press "Enable sudo mode"
    And I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    And I press "Revoke session"
    And I press "Confirm revocation"
    Then I should see "Session revoked."
    And the managed client session should be "invalid"
    And action "SESSION_REVOKED" should be audited for the fixture administrator
