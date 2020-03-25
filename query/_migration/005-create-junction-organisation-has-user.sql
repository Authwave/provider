create table organisation_has_user (
	organisationId int not null ,
	userId int not null ,

	primary key (organisationId, userId) ,

	foreign key organisation_has_user__organisation (organisationId)
		references organisation (id)
		on update cascade
		on delete cascade ,

	foreign key organisation_has_user__user (userId)
		references user (id)
		on update cascade
		on delete cascade
)