create table EmailTemplate (
	applicationId varchar(32) not null,
	scopeId varchar(32) not null,
	deploymentId varchar(32) null,
	name varchar(64) not null,
	subject varchar(256) not null,
	htmlContent mediumtext not null,
	primary key (applicationId, scopeId, name),
	foreign key (applicationId) references Application(id) on update cascade on delete cascade,
	foreign key (deploymentId) references ApplicationDeployment(id) on update cascade on delete cascade
);
