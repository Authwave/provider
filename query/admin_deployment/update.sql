update
	ApplicationDeployment

set
	title = ?,
	providerHost = ?,
	clientHost = ?,
	clientLoginPath = ?,
	domainVerified = ?,
	clientConnected = ?,
	userLoggedIn = ?

where
	id = ?
and
	applicationId = ?
