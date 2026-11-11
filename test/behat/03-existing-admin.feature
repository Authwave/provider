Feature: Administration after an application exists
  Scenario: An assigned application makes the dashboard available
    Given the database is in the "existing_admin" state
    And I sign in as the fixture administrator
    Then the provider path should be "/admin/"
    When I continue account setup
    Then the provider path should be "/integrations/help/"
    And only setup step 3 should be open
    When I go to "/admin/applications/"
    Then I should not see "Create your first application to start using Authwave"

  Scenario: Setup help requires authentication
    Given the database is in the "new_admin" state
    When I go to "/integrations/help/"
    Then the provider path should be "/login/"
