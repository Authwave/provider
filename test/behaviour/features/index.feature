Feature: Index page
	In order to use the application
	As an unauthorised user
	I should be able to interact with the index page.

	Scenario: Main elements exist
		Given I am on the homepage
		Then I should see "Sign in to Authwave"
		And I should see a button labelled "Next"
		And I should see a button labelled "Create account"
		And I should see an input labelled "Your email address"
		And I should see a link labelled "Forgot email?"