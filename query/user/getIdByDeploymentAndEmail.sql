select id
from User
where applicationDeploymentId = ? and email = ?
limit 1
