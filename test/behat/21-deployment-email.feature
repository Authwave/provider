Feature: Deployment email identity
  Background:
    Given the database is in the "new_admin" state
    And I sign in as the fixture administrator
    When I fill in "Application name" with "Email example"
    And I press "Create application"

  @javascript
  Scenario: Sender prefix and Reply-To persist per deployment
    When I complete the "Create a deployment" deployment form:
      | Deployment name        | Production              |
      | Authwave host          | login.example.test      |
      | Client URL             | https://example.test    |
      | Client login path      | /login/                 |
      | Sender email prefix    | account                 |
      | Reply-To email address | help@example.test       |
    Then deployment "Create a deployment" should show sender domain "@login.example.test"
    And the deployment email group should fit narrow and wide screens
    When I save deployment "Create a deployment"
    Then I should see "Deployment saved."
    And deployment "Production" email settings should be "account" and "help@example.test"
    When I reload the page
    Then deployment "Production" email settings should be "account" and "help@example.test"
    When I complete the "Production" deployment form:
      | Authwave host          | account.example.test    |
      | Sender email prefix    | security                |
      | Reply-To email address |                         |
    Then deployment "Production" should show sender domain "@account.example.test"
    When I save deployment "Production"
    Then I should see "Deployment saved."
    And deployment "Production" email settings should be "security" and ""

  Scenario: Invalid sender prefixes are rejected by the server with values retained
    When I complete the "Create a deployment" deployment form:
      | Deployment name        | Production              |
      | Authwave host          | login.example.test      |
      | Client URL             | https://example.test    |
      | Client login path      | /login/                 |
      | Sender email prefix    | forged@example.org      |
      | Reply-To email address | help@example.test       |
    And I submit deployment "Create a deployment" without browser validation
    Then the open deployment form "Create a deployment" should show "Sender email prefix: This field does not match the required pattern" and retain:
      | Sender email prefix    | forged@example.org      |
      | Reply-To email address | help@example.test       |
    And there should be 1 deployment expander
