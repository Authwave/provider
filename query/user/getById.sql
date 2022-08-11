select
	id,
	siteId,
	email,
	hash

from
	user

where
	id = ?

limit 1
