select
	user_field.id,
	userId,
	fieldId,
	value,
	application_field.id as applicationFieldId,
	applicationId,
	type,
	name,
	displayName,
	hint,
	help,
	required,
	sortOrder,
	showOnSignUp

from
	user_field

inner join
	application_field
on
	user_field.fieldId = application_field.id

where
	name = ?
and
	userId = ?