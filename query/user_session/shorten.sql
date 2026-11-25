update UserSession set expiresAt = :expiresAt, idleTimeoutMinutes = :idleTimeoutMinutes
where id = :id and endedAt is null and managed = 1
