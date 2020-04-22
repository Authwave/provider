select
	user.id as userId,
	user.uuid,
	user.deploymentId,
	user.email,
	user.lastLoggedIn,

	application.id as applicationId,
	application.displayName,

	application_deployment.id as deploymentId,
	application_deployment.clientKey,
	application_deployment.clientHost,
	application_deployment.clientLoginHost,

	application_admin.id is not null as admin

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

left join
	application_admin
on
	application_deployment.id = application_admin.deploymentId
and
	application_admin.userId = user.id

where
	user.email = :email
and
	application_deployment.id = :deploymentId