insert into User (
	id,
	applicationDeploymentId,
	email,
	createdAt
)

values (
	:id,
	:applicationDeploymentId,
	:email,
	CURRENT_TIMESTAMP
)
