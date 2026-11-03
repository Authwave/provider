select
	Email.id,
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
	Application.emailSettings

from
	Email

inner join
	ApplicationDeployment
on
	ApplicationDeployment.id = deploymentId

inner join
	Application
on
	Application.id = ApplicationDeployment.applicationId

where
	sentAt is null
and
	scheduledToSendAt <= now()

order by
	id
