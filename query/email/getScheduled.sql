select
	id,
	sentAt,
	sentMessageId,
	createdAt,
	scheduledToSendAt,
	subject,
	toEmail,
	senderName,
	senderAddress,
	textContent,
	htmlContent

from
	email

where
	sentAt is null
and
	scheduledToSendAt <= now()

order by
	id
