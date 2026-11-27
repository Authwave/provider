select
	id,
	title,
	providerHost,
	clientHost,
	clientLoginPath,
	secret,
	domainVerified,
	clientConnected,
	userLoggedIn,
	emailPrefix,
	emailReplyTo,
	emailFromName

from
	ApplicationDeployment

where
	applicationId = ?

order by
	title,
	id
