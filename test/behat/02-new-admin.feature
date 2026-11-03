Feature: Starting administration without an application
  A new administrator must create an application before using application-dependent pages.
  All navigation and setup disclosures work with page JavaScript disabled.

  Background:
    Given the database is in the "new_admin" state
    And I sign in as the fixture administrator

  Scenario: The first visit guides the administrator to create an application
    Then the provider path should be "/admin/applications/"
    And I should see "Create your first application to start using Authwave"
    And I should see "An application is any website or app that requires its own authentication, and can have multiple deployments like development, staging and production."
    And the account setup should show 1 of 7 complete
    And I should see "No applications yet" in the application switcher
    And there should be 1 entry in the application switcher
    And the sidebar should own its semantic landmark
    And the sidebar controls should fit narrow and wide screens

  Scenario: Clicking Dashboard returns to the first-application note
    When I follow the administration link "Dashboard"
    Then the provider path should be "/admin/applications/"
    And the first application note should be highlighted

  Scenario Outline: Application-dependent pages redirect and draw attention to the first step
    When I go to "<path>"
    Then the provider path should be "/admin/applications/"
    And the first application note should be highlighted
    And the account setup should show 1 of 7 complete

    Examples:
      | path                                               |
      | /admin/                                            |
      | /admin/users/                                      |
      | /admin/security/                                   |
      | /admin/emails/                                     |
      | /admin/customisation/                              |
      | /admin/organisation/                               |
      | /admin/?application=unassigned   |

  Scenario Outline: Useful pages remain reachable from the sidebar
    When I follow the administration link "<link>"
    Then the provider path should be "<path>"
    And the account setup should show 1 of 7 complete

    Examples:
      | link         | path                  |
      | Applications | /admin/applications/  |
      | Billing      | /admin/billing/       |
      | Integrations | /admin/integrations/  |

  Scenario: Continue setup opens the current step in a native concertina
    When I continue account setup
    Then the provider path should be "/integrations/help/"
    And the setup guide should contain 7 steps
    And only setup step 2 should be open
    When I open setup step 3
    Then only setup step 3 should be open

  Scenario: A new administrator can still log out
    # TODO: Opportunity for a reusable step: `When I press the sidebar button "Logout"` - in fact, isn't that what `I follow the administration link` does?
    When I press the sidebar logout button
    Then the provider path should be "/logged-out/"
    When I go to "/admin/applications/"
    Then the provider path should be "/login/"
