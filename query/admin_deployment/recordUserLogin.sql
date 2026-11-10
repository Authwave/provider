update
	ApplicationDeployment

set
	userLoggedIn = 1

where
	id = ?
and
	providerHost = ?
and
	domainVerified = 1
and
	userLoggedIn = 0
and
	clientConnected = 1
and
	exists (
		select
			1

		from
			Application

		where
			Application.id = ApplicationDeployment.applicationId
		and
			Application.productionApplicationDeploymentId = ApplicationDeployment.id
	)
