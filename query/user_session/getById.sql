select
	id,
	userId,
	createdAt,
	expiresAt,
	endedAt

from
	UserSession

where
	id = ?
