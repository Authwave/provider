update
	user_identification
set
	confirmed = now()

where
	userId = ?
and
	confirmationCode = ?

limit 1