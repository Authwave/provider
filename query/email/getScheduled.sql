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
	Application.emailSettings,
	ApplicationDeployment.providerHost,
	ApplicationDeployment.emailReplyTo

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
	scheduledToSendAt <= CURRENT_TIMESTAMP

order by
	Email.id
