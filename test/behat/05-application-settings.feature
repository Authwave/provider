Feature: Application-specific settings and deployments
  Background:
    Given the database is in the "new_admin" state
    And I sign in as the fixture administrator
    When I fill in "Application name" with "My website"
    And I press "Create application"
    Then I should be on the settings page for application "My website"

  Scenario: A newly created application has settings and an empty deployment list
    Then the application settings should show its read-only ID and name "My website"
    And the production deployment select should have 0 options
    And I should see "No deployments are assigned to this application yet."
    And there should be 1 deployment expander
    And the new deployment form should retain hidden read-only ID and API key fields

  Scenario: Application settings persist and are audited
    When I fill in "Name" with "My renamed website"
    And I press "Save application settings"
    Then I should see "Application settings saved."
    And the application settings should show its read-only ID and name "My renamed website"
    And the breadcrumb should show "Applications" followed by "My renamed website"
    And action "APPLICATION_UPDATED" should be audited for the fixture administrator
    When I reload the page
    Then the application settings should show its read-only ID and name "My renamed website"

  Scenario: The breadcrumb links back to the application list
    Then the breadcrumb should show "Applications" followed by "My website"
    When I follow "Applications" in the breadcrumb
    Then I should be on "/admin/applications/"
    And I should see "My website"

  Scenario Outline: Applications stays in the same place when navigating
    When I fill in "Name" with "<name>"
    And I press "Save application settings"
    Then I should see "Application settings saved."
    When I follow "Applications" in the breadcrumb
    And I record the Applications heading at a viewport width of <width>
    And I follow "<name>" in the applications table
    Then the breadcrumb should show "Applications" followed by "<name>"
    And the Applications heading should keep its position and style
    And application settings should fit a viewport of <width> by 1000
    When I follow "Applications" in the breadcrumb
    Then the Applications heading should keep its position and style

    Examples:
      | width | name                                                           |
      | 1440  | My website                                                     |
      | 390   | My website                                                     |
      | 280   | AnApplicationWithALongNameWithoutSpacesThatMustWrapOnSmallScreens |

  Scenario: Deployments can be created, edited and selected for production
    When I complete the "Create a deployment" deployment form:
      | Deployment name   | Development               |
      | Host              | login.example.test        |
      | Client URL        | http://localhost:8080     |
      | Client login path | /login/                   |
    And I save deployment "Create a deployment"
    Then I should see "Deployment saved."
    And there should be 2 deployment expanders
    And deployment "Development" should have a saved read-only ID and API key
    And action "DEPLOYMENT_CREATED" should be audited for the fixture administrator
    And the production deployment select should have 2 options
    And I should not see "No deployments are assigned to this application yet."
    When I complete the "Development" deployment form:
      | Deployment name   | Production                |
      | Host              | account.example.test      |
      | Client URL        | https://www.example.test  |
      | Client login path | /authenticate/            |
    And I save deployment "Development"
    Then deployment "Production" should keep the same API key
    And action "DEPLOYMENT_UPDATED" should be audited for the fixture administrator
    When I select "Production" from "Production deployment"
    And I press "Save application settings"
    Then production should use deployment "Production"
    When I reload the page
    Then production should use deployment "Production"
    And deployment "Production" should keep the same API key

  Scenario: Invalid deployment data cannot create a deployment
    When I complete the "Create a deployment" deployment form:
      | Deployment name   | Development               |
      | Host              | https://invalid.test/path |
      | Client URL        | https://www.example.test  |
      | Client login path | /login/                   |
    And I save deployment "Create a deployment"
    Then I should see "Host must be a hostname with an optional port."
    And there should be 1 deployment expander
    And action "APPLICATION_SETTINGS_REJECTED" should be audited for the fixture administrator

  Scenario: An unassigned application cannot be viewed
    When I go to "/admin/applications/provider/"
    Then the response status code should be 404

  Scenario: Application settings remain usable on narrow and wide screens
    Then application settings should fit a viewport of 1440 by 1000
    And application settings should fit a viewport of 390 by 844
    And application settings should fit a viewport of 280 by 800
