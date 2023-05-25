select
	code
from
	user_auth_code

where
	userId = ?
and
	createdAt > date_sub(now(), interval 5 minute)
