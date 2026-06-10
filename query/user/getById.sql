select
	user.id as userId,
	user.applicationDeploymentId,
	user.email,
	user.hash

from
	user

where
	user.id = ?
