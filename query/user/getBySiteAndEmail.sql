select
	id,
	siteId,
	email,
	hash

from
	user

where
	siteId = ?
and
	email = ?

limit 1
