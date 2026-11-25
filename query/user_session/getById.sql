select
	id,
	userId,
	createdAt,
	expiresAt,
	endedAt,
	lastActivityAt,
	idleTimeoutMinutes,
	managed

from
	UserSession

where
	id = ?
