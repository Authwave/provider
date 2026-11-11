select
	ApplicationDeployment.id

from
	ApplicationDeployment

inner join
	Application
on
	Application.id = ApplicationDeployment.applicationId

where
	ApplicationDeployment.providerHost = ?

order by
	(ApplicationDeployment.id = Application.productionApplicationDeploymentId) desc,
	ApplicationDeployment.id

limit
	1
