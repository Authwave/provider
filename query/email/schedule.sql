insert into Email (
	id,
	deploymentId,
	createdAt,
	scheduledToSendAt,
	subject,
	toEmail,
	senderName,
	senderAddress,
	textContent,
	htmlContent
)

values (
	:id,
	:deploymentId,
	CURRENT_TIMESTAMP,
	:scheduledToSendAt,
	:subject,
	:toEmail,
	:senderName,
	:senderAddress,
	:textContent,
	:htmlContent
)
