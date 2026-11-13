create table Application (
	id text primary key, name text not null, emailSendFrom text,
	emailSettings text, productionApplicationDeploymentId text
);
create table ApplicationDeployment (
	id text primary key, applicationId text not null, title text not null,
	secret text not null, providerHost text not null, clientHost text not null,
	clientLoginPath text not null, domainVerified integer not null default 0, clientConnected integer not null default 0, userLoggedIn integer not null default 0, emailPrefix text, emailReplyTo text
);
create table ApplicationTheme (applicationId text, colourScheme text, colours text);
create table User (
	id text primary key, applicationDeploymentId text not null, email text not null, hash text, createdAt text, clientNotificationStatus integer, clientNotificationAcknowledged integer, clientNotificationAction text,
	unique (applicationDeploymentId, email collate nocase),
	foreign key (applicationDeploymentId) references ApplicationDeployment(id) on delete restrict
);
create table Email (
	id text primary key, deploymentId text not null,
	sentAt text, sentMessageId text, createdAt text default CURRENT_TIMESTAMP,
	scheduledToSendAt text, subject text, toEmail text, senderName text,
	senderAddress text, textContent text, htmlContent text,
	foreign key (deploymentId) references ApplicationDeployment(id) on delete restrict
);
create table UserAdmin (userId text, applicationId text, primary key (userId, applicationId));
create table Audit (
	id text primary key, action text, detail text, userId text, anonUserId text, createdAt text default CURRENT_TIMESTAMP
);

create table UserAuthCode (
    id text primary key, userId text not null, code text not null, hash text,
    createdAt text default CURRENT_TIMESTAMP,
    foreign key (userId) references User(id) on delete cascade
);

create table UserSession (
    id text primary key, userId text not null, createdAt text not null,
    expiresAt text not null, endedAt text,
    foreign key (userId) references User(id) on delete cascade
);
