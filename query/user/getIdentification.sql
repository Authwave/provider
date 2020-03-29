select
	id,
	userId,
	identificationType,
	identificationDetail,
	created,
	confirmed,
	confirmationCode

from
	user_identification

where
	userId = ?
and
	confirmationCode = ?

# TODO: Limit the created time to within the cutoff here. This will cause an
# error if someone takes too long to confirm - the error must allow the user
# to start the login again.