select
	user.id as userId,
	user.uuid,
	user.deploymentId,
	user.email,

	application.id as applicationId,
	application.displayName,

	application_deployment.id as deploymentId,
	application_deployment.clientKey,
	application_deployment.clientHost,
	application_deployment.clientLoginHost

from
	user

inner join
	application_deployment
on
	application_deployment.id = user.deploymentId

inner join
	application
on
	application.id = application_deployment.applicationId

where
	user.id = ?