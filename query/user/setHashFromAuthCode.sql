update
	User

inner join
	UserAuthCode
on
	UserAuthCode.userId = User.id
and
	UserAuthCode.hash is not null

set
	User.hash = UserAuthCode.hash

where
	User.id = ?
and
	UserAuthCode.code = ?
