Feature: Production client connection and first sign-in complete account setup
  Background:
    Given the database is in the "production_client" state
    And production client URLs use the test server
    And I sign in as the fixture administrator

  Scenario: A real encrypted client request completes connection before anyone signs in
    When I start a client login on the production provider
    Then I should see "Email address"
    And production client progress should have 1 saved milestones with audits
    When I go to "/admin/applications/managed/"
    Then the account setup should show 4 of 7 complete
    When I reload the page
    Then the account setup should show 4 of 7 complete

  Scenario: The first successful client sign-in completes its own milestone
    When I start a client login on the production provider
    And I sign in as the production customer
    Then production client progress should have 2 saved milestones with audits
    When I go to "/admin/applications/managed/"
    Then the account setup should show 5 of 7 complete

  Scenario: A direct provider visit does not establish a client connection
    When I visit the production provider directly
    Then production client progress should have 0 saved milestones with audits
    When I go to "/admin/applications/managed/"
    Then the account setup should show 3 of 7 complete

  Scenario: A development login does not complete client setup
    When I start a development login on the production provider
    And I sign in as the production customer
    Then production client progress should have 0 saved milestones with audits
    When I go to "/admin/applications/managed/"
    Then the account setup should show 3 of 7 complete
