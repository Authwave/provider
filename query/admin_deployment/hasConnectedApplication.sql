select
	count(*)

from
	ApplicationDeployment deployment

inner join
	Application
on
	Application.id = deployment.applicationId
and
	Application.productionApplicationDeploymentId = deployment.id

inner join
	UserAdmin
on
	UserAdmin.applicationId = Application.id

where
	UserAdmin.userId = ?
and
	deployment.domainVerified = 1
and
	deployment.clientConnected = 1
