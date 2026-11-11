select
	id

from
	User

where
	applicationDeploymentId = ?
and
	lower(email) = lower(?)

limit
	1
