Feature: Deleting deployments with an audit trail
  Deployment deletion must never silently remove users or email history.

  Scenario: Delete the last unused deployment and return to the empty state
    Given the database is in the "deployment_admin" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"
    And I request deletion of deployment "Development"
    Then I should see "Confirm deleting Development" in the deletion confirmation
    When I confirm deletion
    Then I should see "Deployment deleted."
    And I should see "No deployments are assigned to this application yet."
    And there should be 1 deployment expander
    And the production deployment select should have 0 options
    And the deleted deployment should have an audit snapshot without its API key
    When I reload the page
    Then there should be 1 deployment expander

  Scenario: Deleting production clears its selection and preserves other deployments
    Given the database is in the "deployment_admin" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"
    And I complete the "Create a deployment" deployment form:
      | Deployment name   | Staging                   |
      | Authwave host              | staging-login.example.test|
      | Client URL        | https://staging.example.test |
      | Client login path | /login/                   |
    And I save deployment "Create a deployment"
    And I select "Development" from "Production deployment"
    And I press "Save application settings"
    Then production should use deployment "Development"
    When I complete the "Development" deployment form:
      | Client URL | not a valid URL |
    And I request deletion of deployment "Development"
    Then I should see "Confirm deleting Development" in the deletion confirmation
    When I confirm deletion
    Then I should see "Deployment deleted."
    And there should be 2 deployment expanders
    And deployment "Staging" should have a saved read-only ID and API key
    And the production deployment should be unassigned and its removal audited
    And the deleted deployment should have an audit snapshot without its API key
    When I reload the page
    Then the production deployment select should have 2 options

  Scenario Outline: Deployments with dependent records cannot be deleted
    Given the database is in the "<state>" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"
    And I request deletion of deployment "Development"
    Then I should see "Confirm deleting Development" in the deletion confirmation
    When I confirm deletion
    Then I should see "Deployments with users or emails cannot be deleted."
    And there should be 2 deployment expanders
    And deployment "Development" should have a saved read-only ID and API key
    And action "DEPLOYMENT_DELETION_REJECTED" should be audited for the fixture administrator
    And the deployment's dependent "<table>" record should remain

    Examples:
      | state                 | table |
      | deployment_with_users | User  |
      | deployment_with_email | Email |

  Scenario: Cancelling deletion preserves the deployment and allows a fresh confirmation
    Given the database is in the "deployment_admin" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"
    And I request deletion of deployment "Development"
    Then I should see "Confirm deleting Development" in the deletion confirmation
    When I cancel deletion
    Then no deployment should have been deleted
    And deployment "Development" should have a saved read-only ID and API key
    When I request deletion of deployment "Development"
    Then I should see "Confirm deleting Development" in the deletion confirmation
    When I dismiss the deletion confirmation
    Then no deployment should have been deleted
    When I request deletion of deployment "Development"
    Then I should see "Confirm deleting Development" in the deletion confirmation
    When I confirm deletion
    Then I should see "Deployment deleted."
    And the deleted deployment should have an audit snapshot without its API key

  Scenario: The name in the deletion confirmation is plain text
    Given the database is in the "deployment_admin" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"
    And I complete the "Development" deployment form:
      | Deployment name | <b>Development</b> |
    And I save deployment "Development"
    And I request deletion of deployment "<b>Development</b>"
    Then I should see "Confirm deleting <b>Development</b>" in the deletion confirmation
    And the deletion confirmation name should not contain HTML elements
    When I cancel deletion
    Then no deployment should have been deleted
