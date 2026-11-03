create table UserAuthCode (
	id varchar(32) not null primary key ,
	userId varchar(32) not null,
	code varchar(8) not null,
	createdAt datetime not null,
	hash varchar(128) null,

	foreign key(userId)
		references User(id)
		on update cascade
		on delete cascade
)
