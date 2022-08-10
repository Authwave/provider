create table user_token
(
	id varchar(32) not null,
	userId varchar(32) not null,
	token varchar(8) not null,
	createdAt datetime null,
	hash varchar(128) null,
	constraint user_token_pk
		primary key (id),
	constraint user_token_user_id_fk
		foreign key (userId) references user (id)
			on update cascade on delete cascade
);

