delete from
	user_token
where
	createdAt < date_sub(now(), interval 5 minute)
