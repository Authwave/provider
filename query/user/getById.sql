select
	User.id as userId,
	User.applicationDeploymentId,
	User.email,
	User.hash

from
	User

where
	User.id = ?
