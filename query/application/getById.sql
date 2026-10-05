select
	application.id as applicationId,
	application.name,
	application.emailSendFrom,
	application.emailSettings,
	light_theme.colours as lightThemeColours,
	dark_theme.colours as darkThemeColours

from
	application

left join
	application_theme as light_theme
on
	light_theme.applicationId = application.id
	and light_theme.colourScheme = 'light'

left join
	application_theme as dark_theme
on
	dark_theme.applicationId = application.id
	and dark_theme.colourScheme = 'dark'

where
	application.id = ?
