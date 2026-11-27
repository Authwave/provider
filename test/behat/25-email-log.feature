Feature: Application email log
  Background:
    Given the database is in the "existing_admin" state
    And I sign in as the fixture administrator
    And the selected application has email deliveries

  Scenario: Email deliveries can be filtered and paged
    When I go to "/admin/email/"
    Then I should be on "/admin/emails/"
    And the email log should contain 25 rows
    When I follow "Next page"
    Then the email log should contain 4 rows
    And I should see "Page 2"
    When I set the "status" filter in "email-log" to "Sent"
    Then the email log should contain 1 rows
    And I should see "Page 1"
    And I should see "Sent"
    When I set the "status" filter in "email-log" to "Error"
    Then the email log should contain 1 rows
    When I set the "toEmail" filter in "email-log" to "missing@example.test"
    Then I should see "No emails to show"
    When I follow "Reset"
    Then the email log should contain 25 rows

  Scenario: Subjects are displayed and searchable
    When I go to "/admin/emails/"
    Then I should see "Welcome to Authwave"
    When I set the "subject" filter in "email-log" to "WELCOME"
    Then the email log should contain 1 rows
    And I should see "Welcome to Authwave"
    When I set the "subject" filter in "email-log" to "Login"
    Then the email log should contain 25 rows
    When I follow "Next page"
    Then the email log should contain 3 rows
    And the "Subject" field should contain "Login"
