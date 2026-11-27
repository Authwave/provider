update
	Email

set
	sentAt = CURRENT_TIMESTAMP,
	deliveryFailedAt = null,
	sentMessageId = :sentMessageId

where
	id = :id
