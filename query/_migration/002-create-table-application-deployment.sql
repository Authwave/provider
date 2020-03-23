create table application_deployment (
	id int auto_increment ,
	applicationId int not null ,
	clientKey varchar(64) not null unique ,
	clientHost varchar(256) not null ,
	clientLoginHost varchar(256) not null ,

	primary key (id),

	foreign key application_deployment__application (applicationId)
		references application (id)
		on delete cascade
		on update cascade
)