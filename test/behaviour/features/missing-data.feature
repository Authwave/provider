Feature: Missing data
	In order to understand how to set up my application's Authwave provider
	As an application developer
	I should have missing data handled seamlessly

	@db:no-data
	Scenario: Provider is not set up, direct access
		Given I am on the homepage
		Then I should be on "/config"

	@db:no-data
	Scenario: Provider is not set up, login access
		Given I make the login action
		Then I should be on "/config"

	@db:no-data
	Scenario: Provider set up can not be skipped
		Given I go to "/login"
		Then I should be on "/config"

	Scenario: Provider does not show setup when configured
		Given I make the login action
		Then I should be on "/login"

	Scenario: Provider redirects back to client application when no login request is made
		Given I go to "/login"
		Then I should be on the client application
		Given I am on the homepage
		Then I should be on the client application