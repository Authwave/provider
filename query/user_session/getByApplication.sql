select s.id, s.userId, s.createdAt, s.expiresAt, s.endedAt,
 s.lastActivityAt, s.idleTimeoutMinutes, s.managed,
 u.email, d.id as deploymentId, d.title as deploymentTitle
from UserSession s
inner join User u on u.id = s.userId
inner join ApplicationDeployment d on d.id = u.applicationDeploymentId
where d.applicationId = :applicationId
and (:deploymentFilter = '' or d.id = :deploymentId)
and s.endedAt is null and s.expiresAt > :now
order by s.createdAt desc, s.id desc
