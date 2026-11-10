update
	UserSession

set
	endedAt = :endedAt

where
	id = :id
and
	endedAt is null
