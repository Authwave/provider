select
	session.id,
	session.userId,
	session.createdAt,
	session.expiresAt,
	session.endedAt

from
	UserSession session

inner join
	User
on
	User.id = session.userId

inner join
	ApplicationDeployment deployment
on
	deployment.id = User.applicationDeploymentId

inner join
	UserAdmin
on
	UserAdmin.applicationId = deployment.applicationId

where
	UserAdmin.userId = ?
and
	deployment.applicationId = ?
and
	session.endedAt is null
and
	session.expiresAt > ?

order by
	session.createdAt desc,
	session.id desc
