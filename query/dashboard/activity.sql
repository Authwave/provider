select audit.id, audit.action, audit.createdAt, u.id as userId, u.email, deployment.id as deploymentId, deployment.title
from Audit audit

inner join User u on u.id = audit.userId
inner join ApplicationDeployment deployment on deployment.id = u.applicationDeploymentId
inner join UserAdmin administrator on administrator.applicationId = deployment.applicationId
where administrator.userId = :actorId
and deployment.applicationId = :applicationId
and (:deploymentFilter is null or deployment.id = :deploymentId)
and audit.createdAt >= :start
and audit.createdAt < :end
and audit.action in ('LOGIN_COMPLETED', 'PASSWORD_LOGIN_FAILED', 'SECURITY_CODE_REJECTED', 'PASSWORD_CHANGED', 'SECURITY_CODE_REQUESTED', 'LOGOUT_REQUESTED')
and (:filter = '' or audit.action = :action)
order by audit.createdAt desc, audit.id desc
limit :limit offset :offset
