update
	Email

set
	sentAt = CURRENT_TIMESTAMP,
	sentMessageId = :sentMessageId

where
	id = :id
