@javascript
Feature: User details have bookmarkable URLs
  Background:
    Given the database is in the "user_administration" state
    And I sign in as the fixture administrator
    When I go to "/admin/users/?application=managed"

  Scenario: Opening, reloading and navigating popover history preserves the selected user
    When I open user details for "alice@example.test"
    Then the URL and popover should identify user "alice"
    When I reload the page
    Then the URL and popover should identify user "alice"
    When I close the user details
    Then the URL should not select a user popover
    When I go back in user detail history
    Then the URL and popover should identify user "alice"
    When I go forward in user detail history
    Then the URL should not select a user popover

  Scenario: A bookmarked user opens even outside the current search results
    When I go to "/admin/users/?application=managed&search=missing&user=alice"
    Then the URL and popover should identify user "alice"
    And I should see "No users match these filters."
    When I reload the page
    Then the URL and popover should identify user "alice"

  Scenario: A bookmarked user cannot expose another application's user
    When I go to "/admin/users/?application=managed&user=private-user"
    Then the response status code should be 404

  Scenario: A bookmark for a deleted or missing user returns not found
    When I go to "/admin/users/?application=managed&user=missing"
    Then the response status code should be 404
