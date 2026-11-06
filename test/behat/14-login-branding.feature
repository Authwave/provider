Feature: Login identity belongs to the application
  Background:
    Given the database is in the "production_client" state
    And production client URLs use the test server
    And I sign in as the fixture administrator

  Scenario: The selected production deployment shows only the application name
    When I start a client login on the production provider
    Then the login page should identify application "My website"
    And I should see "Log in to My website"
    And the login page should not show a deployment badge
    When I fill in "Email address" with "customer@example.test"
    And I press "Continue"
    Then the provider path should be "/login/authenticate/"
    And the login page should identify application "My website"
    And the login page should not show a deployment badge

  Scenario: A deployment without production selection shows its name as a badge
    When I go to "/admin/applications/managed/"
    And I select "None" from "Production deployment"
    And I press "Save application settings"
    And I start a client login on the production provider
    Then the login page should identify application "My website"
    And I should see "Log in to My website"
    And the login page should show deployment badge "Development" above its content at width 1440
    And the login page should show deployment badge "Development" above its content at width 390
    When I fill in "Email address" with "customer@example.test"
    And I press "Continue"
    Then the provider path should be "/login/authenticate/"
    And the login page should identify application "My website"
    And the login page should show deployment badge "Development" above its content at width 390
