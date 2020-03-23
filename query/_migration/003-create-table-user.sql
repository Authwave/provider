create table user (
	id int auto_increment ,
	uuid varchar(32) not null ,
	deploymentId int not null ,
	email varchar(256) not null ,

	primary key (id),

	foreign key user__application_deployment (deploymentId)
		references application_deployment(id)
		on update cascade
		on delete cascade
)