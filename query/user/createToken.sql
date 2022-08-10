insert into user_token (
	id,
	userId,
	token,
	createdAt,
	hash
)
values (
	:id,
	:userId,
	:token,
	now(),
	:hash
)
