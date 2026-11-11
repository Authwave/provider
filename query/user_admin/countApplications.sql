select
	count(*) as applicationCount

from
	UserAdmin

where
	userId = ?
