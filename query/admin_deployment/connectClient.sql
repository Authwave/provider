update
	ApplicationDeployment

set
	clientConnected = 1

where
	id = ?
and
	providerHost = ?
and
	domainVerified = 1
and
	clientConnected = 0
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
