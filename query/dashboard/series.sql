select substr(audit.createdAt, 1, :bucketLength) as bucket,
 sum(case when audit.action = 'LOGIN_COMPLETED' then 1 else 0 end) as logins,
 sum(case when audit.action in ('PASSWORD_LOGIN_FAILED', 'SECURITY_CODE_REJECTED') then 1 else 0 end) as failures
from Audit audit

inner join User u on u.id = audit.userId
inner join ApplicationDeployment deployment on deployment.id = u.applicationDeploymentId
inner join UserAdmin administrator on administrator.applicationId = deployment.applicationId
where administrator.userId = :actorId
and deployment.applicationId = :applicationId
and (:deploymentFilter is null or deployment.id = :deploymentId)
and audit.createdAt >= :start
and audit.createdAt < :end
and audit.action in ('LOGIN_COMPLETED', 'PASSWORD_LOGIN_FAILED', 'SECURITY_CODE_REJECTED')
group by bucket
order by bucket
