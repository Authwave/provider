insert into User (
	id,
	applicationDeploymentId,
	email,
	hash,
	createdAt
)

values (
	?,
	?,
	?,
	?,
	CURRENT_TIMESTAMP
)
