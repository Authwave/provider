Feature: Application audit log
  Background:
    Given the database is in the "existing_admin" state
    And I sign in as the fixture administrator

  Scenario: Audit filters and pagination are independent of recent security events
    Given the selected application has audit activity
    When I go to "/admin/security/?event=PASSWORD_LOGIN_FAILED&auditEvent=SESSION_POLICY_UPDATED"
    Then the audit log should contain 25 events
    And I should see "You"
    And the security event list should contain 1 events
    When I follow "Next page"
    Then the audit log should contain 2 events
    And the security event list should contain 1 events
    When I follow "Previous page"
    Then the audit log should contain 25 events
    When I set the "auditEvent" filter in "audit" to "Session revoked"
    And I set the "auditDeployment" filter in "audit" to "Security development"
    Then the audit log should contain 1 events
    And the security event list should contain 1 events
    And I should see "Session revoked"
    When I set the "event" filter in "security-events" to "Successful login"
    Then the security event list should contain 25 events
    And the audit log should contain 1 events

  Scenario: Date filters and empty later pages remain usable
    Given the selected application has audit activity
    When I go to "/admin/security/?auditFrom=2020-01-01&auditTo=2020-01-01&auditEvent=SESSION_POLICY_UPDATED"
    Then the audit log should contain 1 events
    When I set the "auditTo" filter in "audit" to "2020-01-02"
    Then the audit log should contain 1 events
    When I set the "auditFrom" filter in "audit" to "2020-01-02"
    Then I should see "No audit events to show"
    When I follow "Reset audit filters"
    Then the audit log should contain 25 events
    When I go to "/admin/security/?auditPage=999"
    Then I should see "No audit events to show"
    And I should see "Previous page"
