select
	Application.id as applicationId,
	Application.name,
	Application.emailSendFrom,
	Application.emailSettings,
	Application.productionApplicationDeploymentId,
	light_theme.colours as lightThemeColours,
	dark_theme.colours as darkThemeColours

from
	Application

left join
	ApplicationTheme as light_theme
on
	light_theme.applicationId = Application.id
and
	light_theme.colourScheme = 'light'

left join
	ApplicationTheme as dark_theme
on
	dark_theme.applicationId = Application.id
and
	dark_theme.colourScheme = 'dark'

where
	Application.id = ?
