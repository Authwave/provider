update Email
set
	sentAt = now(),
	sentMessageId = :sentMessageId

where
	id = :id
