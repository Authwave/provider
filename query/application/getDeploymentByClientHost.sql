select
	application.id as applicationId,
	application.name,
	application.emailSendFrom,
	application.emailSettings,
	light_theme.colours as lightThemeColours,
	dark_theme.colours as darkThemeColours,

	application_deployment.id as applicationDeploymentId,
	application_deployment.title,
	application_deployment.secret,
	application_deployment.clientHost,
	application_deployment.clientLoginPath

from
	application

inner join
	application_deployment
on
	application_deployment.applicationId = application.id

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
	clientHost = ?
