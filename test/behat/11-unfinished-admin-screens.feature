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
      | /admin/emails/         |
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
    And I should see "The remaining security controls are coming soon."
    And I should see "Apply filters"
    When I go to "/admin/security/?from=2020-01-01&to=2020-01-02"
    Then I should see "No security events to show"
    And I should see "Show the last 30 days"

  Scenario: Security events can be filtered and paged
    Given the selected application has recent security events
    When I go to "/admin/security/"
    Then the security event list should contain 25 events
    And I should see "Security development"
    When I follow "Next"
    Then the security event list should contain 3 events
    And I should see "Page 2"
    When I follow "Previous"
    Then the security event list should contain 25 events
    When I select "Password rejected" from "Event"
    And I press "Apply filters"
    Then the security event list should contain 1 events
    And I should see "Rejected"
    And I should see "Page 1"
