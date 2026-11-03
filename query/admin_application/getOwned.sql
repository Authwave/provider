select Application.id, Application.name, Application.productionApplicationDeploymentId
from Application
inner join UserAdmin on UserAdmin.applicationId = Application.id
where Application.id = ? and UserAdmin.userId = ?
