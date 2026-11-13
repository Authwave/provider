update
	ApplicationDeployment

set
	title = ?,
	providerHost = ?,
	clientHost = ?,
	clientLoginPath = ?,
	domainVerified = ?,
	clientConnected = ?,
	userLoggedIn = ?,
	emailPrefix = ?,
	emailReplyTo = ?

where
	id = ?
and
	applicationId = ?
