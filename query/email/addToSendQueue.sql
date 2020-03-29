insert into email (
	userId,
	created,
	toAddress,
	fromAddress,
	subject,
	bodyText,
	bodyHtml
)
values (
	:userId,
	now(),
	:toAddress,
	:fromAddress,
	:subject,
	:bodyText,
	:bodyHtml
)