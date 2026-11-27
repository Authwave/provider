Feature: Deployment sender settings on the email screen
  Background:
    Given the database is in the "new_admin" state
    And I sign in as the fixture administrator
    When I fill in "Application name" with "Email example"
    And I press "Create application"
    And I complete the "Create a deployment" deployment form:
      | Deployment name   | Production           |
      | Authwave host     | login.example.test   |
      | Client URL        | https://example.test |
      | Client login path | /login/              |
    And I save deployment "Create a deployment"
    Then I should see "Deployment saved."

  Scenario: Sender settings live under Senders and survive deployment edits
    Then I should not see "Sender email prefix"
    And I should not see "Support & reply-to email address"
    When I go to "/admin/emails/#senders"
    Then the sender domain should be "@login.example.test"
    And the sender email group should fit narrow and wide screens
    When I fill in "From name" with "Accounts team"
    And I fill in "Sender email prefix" with "account"
    And I fill in "Support & reply-to email address" with "help@example.test"
    And I press "Save sender settings"
    Then I should see "Sender settings saved."
    And the "From name" field should contain "Accounts team"
    And the "Sender email prefix" field should contain "account"
    And the "Support & reply-to email address" field should contain "help@example.test"
    When I reload the page
    And I choose sender "account@login.example.test (Production)"
    Then the "Sender email prefix" field should contain "account"
    When I go to "/admin/applications/"
    And I follow "Email example" in the applications table
    And I complete the "Production" deployment form:
      | Authwave host | account.example.test |
    And I save deployment "Production"
    Then I should see "Deployment saved."
    When I go to "/admin/emails/#senders"
    Then the sender domain should be "@account.example.test"
    And the "From name" field should contain "Accounts team"
    And the "Sender email prefix" field should contain "account"
    And the "Support & reply-to email address" field should contain "help@example.test"

  Scenario: Invalid sender prefixes are rejected with submitted values retained
    When I go to "/admin/emails/#senders"
    And I fill in "Sender email prefix" with "forged@example.org"
    And I fill in "Support & reply-to email address" with "help@example.test"
    And I save the sender without browser validation
    Then I should see "Enter a valid sender prefix"
    And the "Sender email prefix" field should contain "forged@example.org"
    And the "Support & reply-to email address" field should contain "help@example.test"

  Scenario: Empty support address restores the default Reply-To
    When I go to "/admin/emails/#senders"
    Then the "Support & reply-to email address" field should contain "support@example.test"
    When I fill in "Support & reply-to email address" with "help@example.test"
    And I press "Save sender settings"
    Then I should see "Sender settings saved."
    When I fill in "Support & reply-to email address" with ""
    And I press "Save sender settings"
    Then I should see "Sender settings saved."
    And the "Support & reply-to email address" field should contain "support@example.test"

  Scenario: Choosing another sender loads its deployment details automatically
    When I complete the "Create a deployment" deployment form:
      | Deployment name   | Staging              |
      | Authwave host     | staging.example.test |
      | Client URL        | https://stage.test   |
      | Client login path | /login/              |
    And I save deployment "Create a deployment"
    And I go to "/admin/emails/#senders"
    And I choose sender "security@staging.example.test (Staging)"
    Then the sender domain should be "@staging.example.test"
    And the "Support & reply-to email address" field should contain "support@stage.test"
