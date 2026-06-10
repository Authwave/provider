insert into user_auth_code (
	id,
	userId,
	code,
	createdAt,
	hash
)
values (
	:id,
	:userId,
	:code,
	now(),
	:hash
)
