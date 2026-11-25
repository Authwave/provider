insert into UserSession (id, userId, createdAt, expiresAt, lastActivityAt, idleTimeoutMinutes, managed)
values (:id, :userId, :createdAt, :expiresAt, :lastActivityAt, :idleTimeoutMinutes, 1)
