delete from user_auth_code
where
	createdAt < date_sub(now(), interval 30 minute)
