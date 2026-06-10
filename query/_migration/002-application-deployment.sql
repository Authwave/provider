create table application_deployment(
	id varchar(32) not null primary key,
	applicationId varchar(32) not null,
	title varchar(32) not null default 'default',
	secret varchar(32) not null,
	clientHost varchar(256) not null,
	clientLoginPath varchar(256) not null default '/',

	foreign key (applicationId)
		references application(id)
		on update cascade
		on delete cascade
)
