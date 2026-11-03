Feature: Responsive application settings and exclusive deployment disclosures
  Background:
    Given the database is in the "deployment_admin" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"

  Scenario: Settings precede deployments in a responsive layout
    Then application settings should precede deployments and adapt to the screen
    And application form actions should reserve an input label and gap above their buttons

  Scenario: Opening a deployment closes the other deployment disclosure
    Then the create deployment disclosure should be compact with a plus icon
    When I open deployment "Development"
    Then only deployment "Development" should be open
    When I open deployment "Create a deployment"
    Then only deployment "Create a deployment" should be open
    When I open deployment "Development"
    Then only deployment "Development" should be open
    When I close deployment "Development"
    Then there should be no open deployment disclosures
