select Application.id as applicationId, Application.name,
    production.clientHost as client, production.providerHost as loginHost
from Application
inner join UserAdmin on UserAdmin.applicationId = Application.id
left join ApplicationDeployment as production
    on production.id = Application.productionApplicationDeploymentId
    and production.applicationId = Application.id
where UserAdmin.userId = ?
order by Application.name, Application.id
