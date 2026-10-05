create table application_theme (
	applicationId char(64) not null,
	colourScheme varchar(5) not null,
	colours json not null,

	primary key (applicationId, colourScheme),
	constraint application_theme__colourScheme__check
		check (colourScheme in ('light', 'dark')),
	constraint application_theme__applicationId__fk
		foreign key (applicationId)
		references application(id)
		on update cascade
		on delete cascade
);
