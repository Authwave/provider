update email
set
	sentAt = now(),
	sentMessageId = :sentMessageId

where
	id = :id
