select
	*

from
	(
		select
			u.id as userId,
			u.applicationDeploymentId,
			u.email,
			u.hash,
			u.createdAt,
			u.clientNotificationStatus,
			u.clientNotificationAcknowledged,
			u.clientNotificationAction,
			(
				select
					count(*)

				from
					Audit

				where
					userId = u.id
				and
					action = 'LOGIN_COMPLETED'
			) as loginCount,
			(
				select
					max(createdAt)

				from
					Audit

				where
					userId = u.id
				and
					action = 'LOGIN_COMPLETED'
			) as lastLoginAt,
			(
				select
					max(createdAt)

				from
					Audit

				where
					userId = u.id
				and
					action in ('PASSWORD_LOGIN_FAILED', 'SECURITY_CODE_REJECTED')
			) as lastFailureAt

		from
			User u

		inner join
			ApplicationDeployment deployment
		on
			deployment.id = u.applicationDeploymentId

		inner join
			UserAdmin
		on
			UserAdmin.applicationId = deployment.applicationId

		where
			UserAdmin.userId = :actorId
		and
			deployment.applicationId = :applicationId
		and
			u.email like :search escape '!'
		and
			(:userFilter is null or u.id = :userId)
		and
			(:deploymentFilter = '' or u.applicationDeploymentId = :deploymentId)
	) activity

where
	(:failureFilter is null or lastFailureAt >= :since)

order by
	case when :emailSort = 'email' then email end asc,
	case when :createdSort = 'created' then createdAt end desc,
	case when :loginSort = 'logins' then loginCount end desc,
	case when :failureSort = 'failures' then lastFailureAt end desc,
	userId asc

limit
	:limit

offset
	:offset
