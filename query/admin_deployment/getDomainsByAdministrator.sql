select
	ApplicationDeployment.providerHost,
	ApplicationDeployment.domainVerified

from
	ApplicationDeployment

inner join
	UserAdmin
on
	UserAdmin.applicationId = ApplicationDeployment.applicationId

where
	UserAdmin.userId = ?
