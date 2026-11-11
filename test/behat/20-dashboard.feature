Feature: Application dashboard reporting
  Background:
    Given the database is in the "dashboard" state
    And I sign in as the fixture administrator

  Scenario: The dashboard reports real activity across the selected application's deployments
    Then I should see "2" in the "#metric-logins" element
    And I should see "2" in the "#metric-newUsers" element
    And I should see "1" in the "#metric-codes" element
    And I should see "1" in the "#metric-passwords" element
    And I should see "1" in the "#metric-failures" element
    And I should see "66.7%" in the "#metric-successRatio" element
    And I should see "al•••@ex••••••••••" in the "#admin-top-usage" element
    And I should see "bo•@ex••••••••••" in the "#admin-abandoned-users" element
    And I should not see "pr•••••@ex••••••••••"
    And I should not see "ol•@ex••••••••••"
    And I should not see "Event ID"
    And I should not see "This screen is not connected yet."
    And activity email addresses should link to user details
    And the deployment selector should align with the period controls
    And the custom date menu should align right when it wraps
    And the dashboard should fit narrow and wide screens

  Scenario: Activity filters show business events rather than every HTTP request
    When I select "Password rejected" from "Event"
    And I apply the dashboard activity filters
    Then the dashboard activity should show only "Password rejected"
    And I should see "Password rejected" in the ".activity-table" element
    And I should not see "Logged in" in the ".activity-table" element
    And I should not see "Password changed" in the ".activity-table" element
    And I should see "66.7%" in the "#metric-successRatio" element

  Scenario: Period controls and custom dates use real reporting ranges
    When I press "Today"
    Then the dashboard heading should be "Today"
    And the chart data table should show 24 hourly buckets
    And I should see "2" in the "#metric-logins" element
    When I fill in "From" with "2020-01-01"
    And I fill in "To" with "2020-01-02"
    And I press "Apply dates"
    Then the dashboard heading should be "Custom period"
    And I should see "0" in the "#metric-logins" element
    And I should see "—" in the "#metric-successRatio" element
    And I should see "No login activity in this period."
    And I should see "No authentication events match this period and filter."
    And I should see "No users were created in this period."

  Scenario: Dashboard shortcuts use the selected application's live Users screen
    When I follow "Add user"
    Then I should be on "/admin/users/#add-user"
    And I should see "Manually create a new user"

  @javascript
  Scenario: The ECharts comparison follows period changes without reloading the page
    Then the dashboard chart should show 30 points with 2 current and 1 previous logins
    When I mark the current dashboard document
    And I press "7 days"
    Then the dashboard chart should show 7 points with 2 current and 0 previous logins
    And the dashboard document should not have reloaded
    When I open the dashboard comparison controls
    And I select "No comparison" from "Chart comparison"
    And I press "Apply comparison"
    Then the dashboard chart should hide the previous period
    When I press "Today"
    Then the dashboard heading should be "Today"
    And the chart data table should show 24 hourly buckets
    And the dashboard chart should show 24 points with 2 current and 0 previous logins

  Scenario: Reporting defaults to production and preserves deployment selection across controls
    Given the dashboard application's production deployment is "production"
    When I go to "/admin/"
    Then the dashboard should report "Production" with 0 logins
    And I should see "1" in the "#metric-newUsers" element
    When I select "Development" from "Deployment"
    And I apply the report deployment
    Then the dashboard should report "Development" with 2 logins
    When I press "Today"
    Then the dashboard heading should be "Today"
    And the dashboard should report "Development" with 2 logins
    And the chart data table should show 24 hourly buckets
    When I select "All deployments" from "Deployment"
    And I apply the report deployment
    Then the dashboard should report "All deployments" with 2 logins
    And I should see "2" in the "#metric-newUsers" element

  @javascript
  Scenario: An activity email opens the corresponding user details
    When I follow an email in dashboard activity
    Then the URL and popover should identify user "alice"

  Scenario: Activity highlights the production deployment by identity, not by its name
    Given the dashboard application's production deployment is "development"
    When I go to "/admin/"
    Then activity for deployment "Development" should have a production badge
    When I select "All deployments" from "Deployment"
    And I apply the report deployment
    Then the dashboard should report "All deployments" with 2 logins
    And activity for deployment "Development" should have a production badge
