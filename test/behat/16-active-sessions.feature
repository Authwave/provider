Feature: Successful client logins remain current until logout or expiry
  Background:
    Given the database is in the "production_client" state
    And production client URLs use the test server
    And I sign in as the fixture administrator

  Scenario: A real client login and logout update the count and identify the user
    When I go to "/admin/users/"
    Then the Users page should show 0 active client sessions
    When I start a client login on the production provider
    And I sign in as the production customer
    And I go to "/admin/users/"
    Then the Users page should show 1 active client sessions
    And I should see "cu••••••@ex••••••••••" in the "#logged-in-users" element
    When I log out from the production client
    And I go to "/admin/users/"
    Then the Users page should show 0 active client sessions
    And the production customer's session logout should be audited

  Scenario: Expired sessions stop counting without a logout request
    When I start a client login on the production provider
    And I sign in as the production customer
    And I go to "/admin/users/"
    Then the Users page should show 1 active client sessions
    When the production client session expires
    And I reload the page
    Then the Users page should show 0 active client sessions

  Scenario: A development preview does not create a client session
    When I start a development login on the production provider
    And I sign in as the production customer
    And I go to "/admin/users/"
    Then the Users page should show 0 active client sessions

  Scenario: The current users list is capped and scrolls five rows at a time
    Given the database is in the "many_active_sessions" state
    And I sign in as the fixture administrator
    When I go to "/admin/users/"
    Then I should see "29 active sessions" in the "#logged-in-users" element
    And the current users list should show 25 distinct users and "3 more users"
    And the current users list should scroll five rows at a time
