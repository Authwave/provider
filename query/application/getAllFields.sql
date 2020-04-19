select
	application_field.id as fieldId,
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
	application_field

where
	applicationId = ?

order by
	sortOrder