Feature: Deployment domain verification
  Background:
    Given the database is in the "deployment_admin" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"

  Scenario: Public domains show their saved DNS instructions in a native popover
    Then deployment "Development" should show domain status "Domain not verified"
    When I open the domain verification instructions for deployment "Development"
    Then the domain verification popover should show the DNS records for "login.example.test"
    And I should see "You are self-hosting the Authwave provider"
    When I cancel domain verification
    Then the domain verification popover should be closed
    And deployment "Development" should show domain status "Domain not verified"

  Scenario: Reserved test domains verify without DNS records
    When I complete the "Development" deployment form:
      | Authwave host | login.test:8080 |
    And I save deployment "Development"
    And I open the domain verification instructions for deployment "Development"
    And I start checking the deployment domain
    Then deployment "Development" should show domain status "Domain verified"
    And the account setup should show 3 of 7 complete
    And action "DOMAIN_VERIFIED" should be audited for the fixture administrator
    When I reload the page
    Then deployment "Development" should show domain status "Domain verified"

  Scenario Outline: Local deployments need no domain verification
    When I complete the "Development" deployment form:
      | Authwave host | <host> |
    And I save deployment "Development"
    Then deployment "Development" should show domain status "Local domain"
    And deployment "Development" should have a "Local" badge
    And the account setup should show 2 of 7 complete
    When I reload the page
    Then deployment "Development" should show domain status "Local domain"

    Examples:
      | host             |
      | localhost:8080   |
      | 127.23.45.67:80  |

  Scenario: The production deployment is identified in its summary
    When I select "Development" from "Production deployment"
    And I press "Save application settings"
    Then deployment "Development" should have a "Production" badge
    When I select "None" from "Production deployment"
    And I press "Save application settings"
    Then deployment "Development" should not have a "Production" badge

  Scenario: Domain checks are repeatable and stop when leaving the page
    When I complete the "Development" deployment form:
      | Authwave host | login.example.invalid |
    And I save deployment "Development"
    And I open the domain verification instructions for deployment "Development"
    And I start checking the deployment domain
    Then the deployment domain should be checked every ten seconds while this page is open
    And action "DOMAIN_VERIFICATION_CHECKED" should be audited for the fixture administrator
    And domain verification polling should not discard unsaved deployment fields
    When I follow the administration link "Applications"
    Then leaving the page should stop domain verification checks

  Scenario: A deployment outside this application cannot be checked
    When I go to "/admin/applications/managed/?verify=provider"
    Then the response status code should be 404
    And action "DOMAIN_VERIFICATION_CHECKED" should be audited for the fixture administrator

  Scenario: A previously verified public domain completes the setup step
    Given the database is in the "verified_domain" state
    And I sign in as the fixture administrator
    When I go to "/admin/applications/managed/"
    Then deployment "Development" should show domain status "Domain verified"
    And the account setup should show 3 of 7 complete

  Scenario: Hosted-provider instructions use the current request domain
    Then requesting domain instructions on the hosted provider should identify the hosted provider
