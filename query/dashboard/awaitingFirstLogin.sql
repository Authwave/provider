select u.id as userId, u.email, u.createdAt, deployment.title
from User u
inner join ApplicationDeployment deployment on deployment.id = u.applicationDeploymentId
inner join UserAdmin administrator on administrator.applicationId = deployment.applicationId
where administrator.userId = :actorId
and deployment.applicationId = :applicationId
and (:deploymentFilter is null or deployment.id = :deploymentId)
and u.createdAt >= :start
and u.createdAt < :end
and not exists (select 1 from Audit audit where audit.userId = u.id and audit.action = 'LOGIN_COMPLETED')
order by u.createdAt asc, u.id asc
limit 5
