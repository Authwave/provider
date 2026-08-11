insert into audit (
	id,
	action,
	detail,
	userId,
	anonUserId,
	createdAt
)
values (
	:id,
	:action,
	:detail,
	:userId,
	:anonUserId,
	now()
)
