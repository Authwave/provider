select
	code
from
	user_auth_code

where
	userId = ?
and
	createdAt > date_sub(now(), interval 5 minute)

order by
	createdAt desc

limit 1
