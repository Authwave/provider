delete from UserAuthCode
where
	createdAt < date_sub(now(), interval 30 minute)
