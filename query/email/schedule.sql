insert into email (
	id,
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
	now(),
	:scheduledToSendAt,
	:subject,
	:toEmail,
	:senderName,
	:senderAddress,
	:textContent,
	:htmlContent
)
