insert into email (
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
	now(),
	:scheduledToSendAt,
	:subject,
	:toEmail,
	:senderName,
	:senderAddress,
	:textContent,
	:htmlContent
)
