create table user
(
	id varchar(32) not null,
	siteId varchar(32) not null,
	email varchar(128) not null,
	hash varchar(128) null,
	constraint user_pk
		primary key (id),
	constraint user_site_host_fk
		foreign key (siteId) references site (id)
			on update cascade on delete cascade
);
