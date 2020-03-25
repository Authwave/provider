create table organisation_has_application (
	organisationId int not null ,
	applicationId int not null ,

	primary key (organisationId, applicationId) ,

	foreign key organisation_has_application__organisation (organisationId)
		references organisation (id)
		on update cascade
		on delete cascade ,

	foreign key organisation_has_application__application (applicationId)
		references application (id)
		on update cascade
		on delete cascade
)