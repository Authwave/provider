select
	application.id as applicationId,
	application.name,

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

where
	application_deployment.id = ?
