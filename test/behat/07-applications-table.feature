Feature: Applications list with production deployment hosts
  Background:
    Given the database is in the "application_list" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/"

  Scenario: Each owned application has one row with its production hosts or hyphens
    Then the applications table should contain:
      | Name                  | ID             | Client                   | Login host         |
      | No deployments        | empty-app      | -                        | -                  |
      | Production website    | production-app | https://www.example.test | login.example.test |
      | Unassigned production | unassigned-app | -                        | -                  |
    And I should not see "Private application"
    And every application table cell should link to its row target
    And the applications list should precede the create panel and adapt to the screen
    And the applications table should fit a viewport of 1440 by 1000
    And the applications table should fit a viewport of 390 by 844
    And the applications table should fit a viewport of 280 by 800

  Scenario Outline: Every application cell opens its application settings
    When I follow the "<column>" cell for application "Production website"
    Then I should be on "/admin/applications/production-app/"

    Examples:
      | column     |
      | Name       |
      | ID         |
      | Client     |
      | Login host |

  Scenario: Host columns follow changes to the selected production deployment
    When I follow "Production website" in the applications table
    Then I should be on "/admin/applications/production-app/"
    When I select "Staging" from "Production deployment"
    And I press "Save application settings"
    Then I should see "Application settings saved."
    When I follow "Applications" in the breadcrumb
    Then the applications table should contain:
      | Name                  | ID             | Client                       | Login host                |
      | No deployments        | empty-app      | -                            | -                         |
      | Production website    | production-app | https://staging.example.test | staging-login.example.test|
      | Unassigned production | unassigned-app | -                            | -                         |
    When I follow "Production website" in the applications table
    And I select "None" from "Production deployment"
    And I press "Save application settings"
    Then I should see "Application settings saved."
    When I follow "Applications" in the breadcrumb
    Then the applications table should contain:
      | Name                  | ID             | Client | Login host |
      | No deployments        | empty-app      | -      | -          |
      | Production website    | production-app | -      | -          |
      | Unassigned production | unassigned-app | -      | -          |
