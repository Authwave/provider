select
	count(*)

from
	User u

inner join
	ApplicationDeployment deployment
on
	deployment.id = u.applicationDeploymentId

inner join
	UserAdmin
on
	UserAdmin.applicationId = deployment.applicationId

where
	UserAdmin.userId = ?
and
	deployment.applicationId = ?
and
	u.email like ? escape '!'
and
	(? = '' or u.applicationDeploymentId = ?)
