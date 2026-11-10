select
	deployment.id

from
	ApplicationDeployment deployment

join
	User
on
	User.applicationDeploymentId = deployment.id

where
	deployment.applicationId = ?
and
	User.email = ?

order by
	deployment.title,
	deployment.id
