insert into user_field(
	userId,
	fieldId,
	value
)

select
	:userId,
	application_field.id,
	:value

from
	application_field
where
	application_field.name = :fieldName