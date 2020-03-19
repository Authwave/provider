select
	application.id as applicationId,
	application.displayName,

	application_deployment.id as deploymentId,
	application_deployment.clientKey,
	application_deployment.clientHost,
	application_deployment.clientLoginHost

from
	application

inner join
	application_deployment
on
	application_deployment.applicationId = application.id
and
	application_deployment.clientLoginHost = ?