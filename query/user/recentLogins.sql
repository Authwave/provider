select
	createdAt

from
	Audit

where
	userId = ?
and
	action = 'LOGIN_COMPLETED'

order by
	createdAt desc,
	id desc

limit
	10
