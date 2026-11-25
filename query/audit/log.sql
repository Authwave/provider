with context as (
	select audit.id, audit.action, audit.createdAt, u.email, u.applicationDeploymentId,
		coalesce(json_extract(audit.detail, '$.applicationId'),
			json_extract(audit.detail, '$.to.applicationId'),
			json_extract(audit.detail, '$.from.applicationId')) as applicationScope,
		coalesce(json_extract(audit.detail, '$.deploymentId'),
			json_extract(audit.detail, '$.to.deploymentId'),
			json_extract(audit.detail, '$.from.deploymentId'),
			case when audit.action in ('DEPLOYMENT_CREATED', 'DEPLOYMENT_UPDATED', 'DEPLOYMENT_DELETED')
				then coalesce(json_extract(audit.detail, '$.to.id'), json_extract(audit.detail, '$.from.id')) end
		) as deploymentScope
	from Audit audit
	left join User u on u.id = audit.userId
	where audit.createdAt >= :start and audit.createdAt < :end
	and (:filter = '' or audit.action = :action)
)
select context.id, context.action, context.createdAt, context.email, deployment.title,
	case when context.deploymentScope is not null or deployment.id is not null then 1 else 0 end as hasDeployment
from context
left join ApplicationDeployment deployment on json_extract(json_quote(deployment.id), '$') = coalesce(
	context.deploymentScope,
	case when context.applicationScope is null then json_extract(json_quote(context.applicationDeploymentId), '$') end
)
where exists (select 1 from UserAdmin administrator
	where administrator.userId = :actorId and administrator.applicationId = :applicationId)
and (context.applicationScope = json_extract(json_quote(:scopeApplicationId), '$')
	or (context.applicationScope is null and deployment.applicationId = :fallbackApplicationId))
and (:deploymentFilter = '' or deployment.id = :deploymentId
	or context.deploymentScope = json_extract(json_quote(:contextDeploymentId), '$'))
order by context.createdAt desc, context.id desc
limit :limit offset :offset
