select u.id as userId, u.email, u.createdAt, deployment.title
from User u
inner join ApplicationDeployment deployment on deployment.id = u.applicationDeploymentId
inner join UserAdmin administrator on administrator.applicationId = deployment.applicationId
where administrator.userId = :actorId
and deployment.applicationId = :applicationId
and (:deploymentFilter is null or deployment.id = :deploymentId)
and u.createdAt >= :start
and u.createdAt < :end
order by u.createdAt desc, u.id desc
limit 5
