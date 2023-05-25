create table user_auth_code (
	id varchar(32) not null primary key ,
	userId varchar(32) not null,
	code varchar(8) not null,
	createdAt datetime not null,
	hash varchar(128) null,

	foreign key(userId)
		references user(id)
		on update cascade
		on delete cascade
)
