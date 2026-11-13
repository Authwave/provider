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
      | Support & reply-to email address | help@example.test       |
    Then deployment "Create a deployment" should show sender domain "@login.example.test"
    And no deployment changes should have been saved
    And the deployment email group should fit narrow and wide screens
    When I save deployment "Create a deployment"
    Then I should see "Deployment saved."
    And deployment "Production" email settings should be "account" and "help@example.test"
    When I reload the page
    Then deployment "Production" email settings should be "account" and "help@example.test"
    When I complete the "Production" deployment form:
      | Authwave host          | account.example.test    |
      | Sender email prefix    | security                |
      | Support & reply-to email address |                         |
    Then deployment "Production" should show sender domain "@account.example.test"
    And stored deployment "Production" should use host "login.example.test"
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
      | Support & reply-to email address | help@example.test       |
    And I submit deployment "Create a deployment" without browser validation
    Then the open deployment form "Create a deployment" should show "Sender email prefix: This field does not match the required pattern" and retain:
      | Sender email prefix    | forged@example.org      |
      | Support & reply-to email address | help@example.test       |
    And there should be 1 deployment expander

  Scenario: A read-only preview supports incomplete drafts and ordinary form submissions
    When I complete the "Create a deployment" deployment form:
      | Authwave host          | LOGIN.Example.TEST:8443 |
      | Client URL             | https://client.example.test |
      | Sender email prefix    | account                |
      | Support & reply-to email address | help@example.test      |
    And I update the email preview for deployment "Create a deployment"
    Then deployment "Create a deployment" should show sender domain "@login.example.test"
    And deployment "Create a deployment" should have Reply-To placeholder "e.g. support@client.example.test"
    And deployment "Create a deployment" should retain:
      | Deployment name        |                        |
      | Authwave host          | LOGIN.Example.TEST:8443 |
      | Client URL             | https://client.example.test |
      | Sender email prefix    | account                |
      | Support & reply-to email address | help@example.test      |
    And no deployment changes should have been saved
    When I complete the "Create a deployment" deployment form:
      | Authwave host | https://invalid.test/path |
    And I update the email preview for deployment "Create a deployment"
    Then no deployment changes should have been saved
    And deployment "Create a deployment" should retain:
      | Authwave host | https://invalid.test/path |
    When I complete the "Create a deployment" deployment form:
      | Deployment name   | Production           |
      | Authwave host     | login.example.test   |
      | Client URL        | https://example.test |
      | Client login path | /login/              |
    And I save deployment "Create a deployment"
    Then I should see "Deployment saved."
    And deployment "Production" email settings should be "account" and "help@example.test"

  @javascript
  Scenario: Save clicked immediately after a host change waits for the preview
    When I complete the "Create a deployment" deployment form:
      | Deployment name        | Production           |
      | Authwave host          | login.example.test   |
      | Client URL             | https://example.test |
      | Client login path      | /login/              |
      | Sender email prefix    | account              |
      | Support & reply-to email address | help@example.test    |
    And I change the host to "account.example.test" and immediately save deployment "Create a deployment"
    Then I should see "Deployment saved."
    And stored deployment "Production" should use host "account.example.test"
    And deployment "Production" email settings should be "account" and "help@example.test"

  @javascript
  Scenario: Sender uses the Authwave host and support placeholder follows the client
    When I complete the "Create a deployment" deployment form:
      | Deployment name     | Local                 |
      | Client login path   | /login/               |
      | Support & reply-to email address | help@example.test |
      | Client URL          | http://localhost:8080 |
      | Authwave host       | login.api.horse       |
      | Sender email prefix | support               |
    And I update the email preview for deployment "Create a deployment"
    Then deployment "Create a deployment" should show sender domain "@login.api.horse"
    And deployment "Create a deployment" should have Reply-To placeholder "e.g. support@localhost"
    When I complete the "Create a deployment" deployment form:
      | Client URL | https://WWW.Example.test:8443 |
    And I update the email preview for deployment "Create a deployment"
    Then deployment "Create a deployment" should have Reply-To placeholder "e.g. support@example.test"
    And deployment "Create a deployment" should show sender domain "@login.api.horse"
