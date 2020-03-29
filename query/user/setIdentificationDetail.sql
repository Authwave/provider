insert into user_identification (
	userId,
	identificationType,
	identificationDetail,
	created
)
values (
	:userId,
	:type,
	:detail,
	now()
)