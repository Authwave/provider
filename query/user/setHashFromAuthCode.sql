update user
inner join
	user_auth_code
on
	user_auth_code.userId = user.id
and
	user_auth_code.hash is not null

set
	user.hash = user_auth_code.hash
where
	user.id = ?
and
	user_auth_code.code = ?
