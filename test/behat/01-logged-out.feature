Feature: Logged-out landing page
  The provider can render its public logout destination without a client application.

  Scenario: A signed-out visitor can see the logout destination
    Given I am on "/logged-out/"
    Then I should see "You have been logged out"
    And I should see "Your administrator session has ended."
    And I should see "Sign in again"

  Scenario: The landing page survives a fresh browser visit
    Given I am on "/logged-out/"
    When I reload the page
    Then I should see "You have been logged out"
