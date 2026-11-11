Feature: Redirecting visitors to the client login page
  Scenario: A direct provider visit redirects to the client and records the destination
    Given the database is in the "new_admin" state
    And the client login destination is the local logged-out page
    When I go to "/"
    Then I should be on "/logged-out/"
    And the client login redirect should be audited
