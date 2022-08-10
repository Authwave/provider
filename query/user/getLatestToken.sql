select
	token

from
	user_token

where
	userId = ?
and
	createdAt > date_sub(now(), interval 5 minute)
