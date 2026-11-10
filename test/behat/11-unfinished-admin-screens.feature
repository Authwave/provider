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
      | /admin/                |
      | /admin/security/       |
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

