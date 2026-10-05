create table user_admin (
	userId char(64) not null,
	applicationId char(64) not null,

	primary key (userId, applicationId),
	constraint user_admin__userId__fk
		foreign key (userId)
		references user(id)
		on update cascade
		on delete cascade,
	constraint user_admin__applicationId__fk
		foreign key (applicationId)
		references application(id)
		on update cascade
		on delete cascade
);
