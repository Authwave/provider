select u.id as userId, u.email, deployment.title, count(*) as logins
from Audit audit

inner join User u on u.id = audit.userId
inner join ApplicationDeployment deployment on deployment.id = u.applicationDeploymentId
inner join UserAdmin administrator on administrator.applicationId = deployment.applicationId
where administrator.userId = :actorId
and deployment.applicationId = :applicationId
and (:deploymentFilter is null or deployment.id = :deploymentId)
and audit.createdAt >= :start
and audit.createdAt < :end
and audit.action = 'LOGIN_COMPLETED'
group by u.id, u.email, deployment.title
order by logins desc, u.id asc
limit 5
