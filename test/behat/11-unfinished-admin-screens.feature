Feature: Administration without prototype behaviour
  Background:
    Given the database is in the "existing_admin" state
    And I sign in as the fixture administrator

  Scenario Outline: Unfinished screens retain their layouts without simulated functionality
    When I go to "<path>"
    Then I should see "This screen is not connected yet. Its controls are unavailable."
    And unfinished controls should be disabled
    And prototype records should not be displayed

    Examples:
      | path                   |
      | /admin/customisation/  |
      | /admin/organisation/   |
      | /admin/billing/        |

  Scenario: Real navigation and account controls remain usable
    Then the sidebar should own its semantic landmark
    And the sidebar controls should fit narrow and wide screens
    And the sidebar should provide the correct scripting fallback
    When I follow the administration link "Applications"
    Then I should be on "/admin/applications/"
    And I should see "Customer website" in the application switcher
    When I continue account setup
    Then I should be on "/integrations/help/"


  Scenario: Security events are live while future security controls remain unavailable
    When I go to "/admin/security/"
    Then I should see "Recent security events"
    And I should see "Coming soon"
    And I should see "Reset"
    When I go to "/admin/security/?from=2020-01-01&to=2020-01-02"
    Then I should see "No security events to show"
    And I should see "Show the last 30 days"

  Scenario: Security events can be filtered and paged
    Given the selected application has recent security events
    When I go to "/admin/security/"
    Then the security event list should contain 25 events
    And the security panels should follow the section order and stack on mobile
    And I should see "Security development"
    When I follow "Next"
    Then the security event list should contain 3 events
    And I should see "Page 2"
    When I follow "Previous"
    Then the security event list should contain 25 events
    When I set the "event" filter in "security-events" to "Password rejected"
    Then the security event list should contain 1 events
    And I should see "Rejected"
    And I should see "Page 1"

  Scenario Outline: Security settings require sudo even when disabled controls are bypassed
    When I go to "/admin/security/"
    And I submit the unavailable security form "<control>"
    Then I should be on "/login/sudo/"
    And action "SUDO_REQUESTED" should be audited for the fixture administrator
    When I fill in "Password" with "Incorrect-password"
    And I press "Enable sudo mode"
    Then I should see "Your password was not accepted. Please try again."
    When I fill in "Password" with "Test-admin-password-2026"
    And I press "Enable sudo mode"
    Then I should be on "/admin/security/?application=customer"
    And sudo mode should be enabled
    And action "SUDO_AUTHORISED" should be audited for the fixture administrator
    When I submit the unavailable security form "<control>"
    Then I should be on "/admin/security/?application=customer"
    When the sudo period expires
    And I submit the unavailable security form "<control>"
    Then I should be on "/login/sudo/"

    Examples:
      | control        |
      | authentication |
      | timeouts       |
      | revocation     |

  Scenario: Cancelling security sudo leaves the controls unavailable
    When I go to "/admin/security/"
    And I submit the unavailable security form "revocation"
    And I press "Cancel"
    Then I should be on "/admin/security/?application=customer"
    And sudo mode should be disabled
    And I should see "Coming soon"

  Scenario: Security panels follow the section order
    When I go to "/admin/security/"
    Then the security panels should follow the section order and stack on mobile

  Scenario: Email log is available alongside unfinished email settings
    When I go to "/admin/emails/"
    Then I should see "Email log"
    And I should see "No emails to show"
    When I set the "toEmail" filter in "email-log" to "nobody@example.test"
    Then I should see "No emails to show"
    And the "To email" field should contain "nobody@example.test"
