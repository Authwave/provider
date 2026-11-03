Feature: Missing deployments
  Scenario Outline: An unknown deployment cannot start authentication
    Given the database is in the "new_admin" state
    When I go to "<path>"
    Then the response status code should be 404
    And I should not see "Email address"

    Examples:
      | path                               |
      | /?dev-login=missing                 |
      | /?d=missing&c=invalid&i=invalid      |
