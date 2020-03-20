Feature: Unconfigured provider
	In order to understand how to set up my application's Authwave provider
	As an application developer
	I should be instructed how to set up my Authwave provider

	@db:no-data
	Scenario: Application is not set up, direct access
		Given I am on the homepage
		Then I should be on "/setup"

	@db:no-data
	Scenario: Application is not set up, login access
		Given I make the login action
		Then I should be on "/setup"

	@db:no-data
	Scenario: Application set up can not be skipped
		Given I go to "/login"
		Then I should be on "/setup"

	Scenario: Application does not show setup when configured
		Given I make the login action
		Then I should be on "/login"