delete from user_auth_code
where
	userId = ?
and
	code = ?
