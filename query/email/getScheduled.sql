select
	email.id,
	sentAt,
	sentMessageId,
	createdAt,
	scheduledToSendAt,
	subject,
	toEmail,
	senderName,
	senderAddress,
	textContent,
	htmlContent,
	application.emailSettings

from
	email

inner join
	application_deployment
on
	application_deployment.id = deploymentId

inner join
	application
on
	application.id = application_deployment.applicationId

where
	sentAt is null
and
	scheduledToSendAt <= now()

order by
	id
