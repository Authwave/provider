update
	User

set
	clientNotificationStatus = ?,
	clientNotificationAcknowledged = ?,
	clientNotificationAction = ?

where
	id = ?
