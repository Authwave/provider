update UserSession set lastActivityAt = :lastActivityAt
where id = :id and endedAt is null and managed = 1
