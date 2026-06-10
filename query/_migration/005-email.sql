create table email (
	id varchar(32) not null primary key,
	sentAt datetime null,
	sentMessageId varchar(256) null,
	createdAt datetime not null,
	scheduledToSendAt datetime null,
	subject varchar(256) not null,
	toEmail varchar(256) not null,
	senderName varchar(256) not null,
	senderAddress varchar(256) not null,
	textContent text not null,
	htmlContent text not null,

	index (sentAt),
	index (sentMessageId),
	index (createdAt),
	index (subject),
	index (toEmail)
)
