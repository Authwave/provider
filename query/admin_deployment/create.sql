insert into ApplicationDeployment (
	id,
	applicationId,
	title,
	providerHost,
	clientHost,
	clientLoginPath,
	secret,
	emailPrefix,
	emailReplyTo
)

values (
	?,
	?,
	?,
	?,
	?,
	?,
	?,
	?,
	?
)
