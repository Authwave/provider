create table application_field (
	id int auto_increment ,
	applicationId int not null ,
	type varchar(16) ,
	name varchar(64) not null ,
	displayName varchar(64) ,
	hint varchar(128) ,
	help text ,
	required bool ,
	sortOrder int ,
	showOnSignUp bool ,

	primary key (id) ,

	foreign key application_field__application (applicationId)
		references application(id)
		on update cascade
		on delete cascade
)