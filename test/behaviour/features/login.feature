Feature: Log in flow
	In order to use my client application
	As a client application user
	I should be able to log in or sign up through Authwave

	Scenario Outline: Email confirmation link can be clicked to return
		Given I make the login action
		Then I should be on "/login"
		When I fill in "email" with "<email>"
		And I press "Continue"
		Then I should be on "/login/authenticate"
		And I should see the confirmed email as <email>

		When I follow the confirmed email link
		Then I should be on "/login"
		And the "email" field should contain "<email>"

		Examples:
			|email|
			|example1@test.authwave.com|
			|example2@test.authwave.com|

	Scenario Outline: Short passwords can not be used
		Given I make the login action
		When I fill in "email" with "<email>"
		And I press "Continue"
		And I fill in "password" with "<password>"
		And I press "Log in with password"
		Then I should be on "/login/authenticate"
		And I should see an "error" flash message reading "Your password is too short, please pick a stronger one with at least 12 characters"

		Examples:
			|email|password|
			|example1@test.authwave.com||
			|example2@test.authwave.com|hunter2|
			|example3@test.authwave.com|i~L1k3+c@75|