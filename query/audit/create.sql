insert into Audit (
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
	CURRENT_TIMESTAMP
)
