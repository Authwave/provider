create table user (
	id varchar(32) not null primary key,
	applicationDeploymentId varchar(32) not null,
	email varchar(256) not null,
	hash varchar(256) null,

	index email_index(email),

	foreign key (applicationDeploymentId)
		references application_deployment(id)
		on update cascade
		on delete cascade
)
