create table site
(
	`id` varchar(32) not null,
	`host` varchar(128) not null,
	`uri` varchar(128) null,
	`apiKey` varchar(128) not null,
	`name` varchar(128) null,
	constraint site_pk
		primary key (id)
);
