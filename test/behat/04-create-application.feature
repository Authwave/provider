Feature: Creating real applications
  Background:
    Given the database is in the "new_admin" state
    And I sign in as the fixture administrator

  Scenario: My first application persists and unlocks the admin section
    When I fill in "Application name" with "My first website"
    And I press "Create application"
    Then I should be on the settings page for application "My first website"
    And I should see "Application created."
    And I should see "My first website"
    And the application "My first website" should be saved for the fixture administrator
    And creation of application "My first website" should be audited
    And action "PASSWORD_LOGIN_SUCCEEDED" should be audited for the fixture administrator
    And action "LOGIN_COMPLETED" should be audited for the fixture administrator
    And action "REQUEST_RECEIVED" should be audited for the fixture administrator
    And the account setup should show 2 of 7 complete
    And I should not see "Create your first application to start using Authwave"
    When I reload the page
    Then I should see "My first website"
    And the application switcher should select "My first website"
    When I follow the administration link "Dashboard"
    Then the provider path should be "/admin/"
    When I press the sidebar logout button
    Then action "LOGOUT_REQUESTED" should be audited for the fixture administrator
    And I sign in as the fixture administrator
    And I go to "/admin/applications/"
    Then I should see "My first website"
    And the application switcher should select "My first website"

  Scenario: Missing meaningful input does not create an application
    When I fill in "Application name" with "   "
    And I press "Create application"
    Then I should see "Enter an application name."
    And the fixture administrator should have 0 saved applications
    And action "APPLICATION_CREATION_REJECTED" should be audited for the fixture administrator
    And the account setup should show 1 of 7 complete

  Scenario: Markup in an application name is displayed as text
    When I fill in "Application name" with "<strong>My website</strong>"
    And I press "Create application"
    Then I should be on the settings page for application "<strong>My website</strong>"
    And I should see "<strong>My website</strong>"
    And no application name markup should be interpreted as HTML

  Scenario Outline: Malformed creation requests are rejected and audited
    When I submit invalid application data "<case>"
    Then I should see "<message>"
    And the fixture administrator should have 0 saved applications
    And action "APPLICATION_CREATION_REJECTED" should be audited for the fixture administrator

    Examples:
      | case    | message                                           |
      | missing | Enter an application name.                        |
      | array   | Enter a single application name.                  |
      | long    | Application names must be 128 characters or fewer. |

  Scenario: Multiple saved applications can be selected without changing ownership
    When I fill in "Application name" with "First website"
    And I press "Create application"
    And I follow the administration link "Applications"
    And I fill in "Application name" with "Second website"
    And I press "Create application"
    Then the fixture administrator should have 2 saved applications
    And the application switcher should select "Second website"
    When I switch to application "First website"
    Then the application switcher should select "First website"
    When I follow the administration link "Dashboard"
    Then the application switcher should select "First website"
    When I reload the page
    Then the application switcher should select "First website"
