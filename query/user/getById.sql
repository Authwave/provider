select
	User.id as userId,
	User.applicationDeploymentId,
	User.email,
	User.hash,
	User.createdAt,
	User.clientNotificationStatus,
	User.clientNotificationAcknowledged,
	User.clientNotificationAction

from
	User

where
	User.id = ?
