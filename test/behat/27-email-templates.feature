Feature: Configurable email templates
  Background:
    Given the database is in the "existing_admin" state
    And I sign in as the fixture administrator
    And the selected application has email deliveries
    When I go to "/admin/emails/#templates"

  Scenario: Template selection loads the real templates with sample previews
    Then I should see "Using default template"
    And the email template subject should be "Your {{siteName}} security code is {{code}}"
    And the email template preview should contain "123456"
    When I choose email template "Login instructions"
    Then the email template subject should be "Your account for {{applicationName}}"
    And the email template preview should contain "person@example.test"
    When I choose email template "Password changed"
    Then the email template subject should be "Your {{siteName}} password has changed"
    And the email template preview should contain "Your password has changed"

  Scenario: Live preview preserves disclosure state and saving persists edits
    When I open the email template preview
    And I edit the email template:
      | Subject       | Custom {{siteName}} code                                                               |
      | HTML template | <html><head><title>Old title</title></head><body><p>New code: {{code}}</p></body></html> |
    Then the email template preview should contain "New code: 123456"
    And the email template preview should stay open
    When I submit the template action "Save settings"
    Then I should see "Email template saved."
    When I reload the page
    Then the email template subject should be "Custom {{siteName}} code"
    And the email template preview should contain "New code: 123456"
    When I submit the template action "Reset to default"
    Then I should see "Default email template restored."
    And I should see "Using default template"
    And the email template subject should be "Your {{siteName}} security code is {{code}}"

  Scenario: Previews do not save drafts
    When I edit the email template:
      | Subject | Unsaved subject |
    Then the email template subject should be "Unsaved subject"
    When I reload the page
    Then the email template subject should be "Your {{siteName}} security code is {{code}}"

  Scenario: Deployment overrides are separate from application defaults
    When I choose email template "Password changed"
    And I edit the email template:
      | Subject | Application password notification |
    And I submit the template action "Save settings"
    Then I should see "Email template saved."
    When I choose template configuration "Security development"
    Then the email template subject should be "Application password notification"
    When I edit the email template:
      | Subject | Development password notification |
    And I submit the template action "Save settings"
    Then I should see "Email template saved."
    When I choose template configuration "Application default"
    Then the email template subject should be "Application password notification"
    When I choose template configuration "Security development"
    Then the email template subject should be "Development password notification"

  Scenario: Invalid templates retain the draft and do not overwrite saved content
    When I edit the email template:
      | HTML template | <title>Missing code</title><p>Invalid</p> |
    Then I should see "Keep the {{code}} placeholder in the HTML template."
    When I submit the template action "Save settings"
    Then I should see "Keep the {{code}} placeholder in the HTML template."
    And the "HTML template" field should contain "<title>Missing code</title><p>Invalid</p>"
    When I reload the page
    Then the email template preview should contain "123456"

  Scenario: Saving an unchanged template continues to use the default
    Then I should see "Using default template"
    When I submit the template action "Save settings"
    Then I should see "Email template saved."
    And I should see "Using default template"
