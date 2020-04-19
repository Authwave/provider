select
	user_field.id as userFieldId,
	userId,
	fieldId,
	value,

	application_field.applicationId,
	application_field.type,
	application_field.name,
	application_field.displayName,
	application_field.hint,
	application_field.help,
	application_field.required,
	application_field.showOnSignUp,

	application.displayName as applicationDisplayName

from
	user_field

inner join
	application_field
on
	user_field.fieldId = application_field.id

inner join
	application
on
	application_field.applicationId = application.id

where
	userId = ?