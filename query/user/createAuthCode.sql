insert into UserAuthCode (
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
