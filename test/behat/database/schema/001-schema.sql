create table Application (
	id text primary key, name text not null, emailSendFrom text,
	emailSettings text, productionApplicationDeploymentId text
);
create table ApplicationDeployment (
	id text primary key, applicationId text not null, title text not null,
	secret text not null, providerHost text not null, clientHost text not null,
	clientLoginPath text not null, domainVerified integer not null default 0, clientConnected integer not null default 0, userLoggedIn integer not null default 0
);
create table ApplicationTheme (applicationId text, colourScheme text, colours text);
create table User (
	id text primary key, applicationDeploymentId text not null, email text not null, hash text,
	foreign key (applicationDeploymentId) references ApplicationDeployment(id) on delete restrict
);
create table Email (
	id text primary key, deploymentId text not null,
	foreign key (deploymentId) references ApplicationDeployment(id) on delete restrict
);
create table UserAdmin (userId text, applicationId text, primary key (userId, applicationId));
create table Audit (
	id text primary key, action text, detail text, userId text, anonUserId text, createdAt text
);
