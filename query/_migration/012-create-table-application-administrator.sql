create table application_admin(
	id int auto_increment ,
	deploymentId int not null ,
	userId int not null ,
	created datetime ,
	createdBy int,

	primary key (id) ,

	foreign key application_admin__application_deployment (deploymentId)
		references application_deployment (id)
		on update cascade
		on delete cascade ,

	foreign key application_admin__user__admin (userId)
		references user (id)
		on update cascade
		on delete cascade ,

	foreign key application_admin__user__createdBy (createdBy)
		references user (id)
		on update cascade
		on delete cascade
)