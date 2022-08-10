update user
inner join
	user_token
on
	user_token.userId = user.id
and
	user_token.hash is not null

set
	user.hash = user_token.hash
where
	user.id = ?
and
	user_token.token = ?
