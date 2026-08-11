create table audit (
	id char(64) not null primary key,
	action varchar(64) not null,
	detail json null,
	userId char(64) null,
	anonUserId varchar(8) null,
	createdAt datetime not null,

	index audit__action__index(action),
	index audit__userId__index(userId),
	index audit__anonUserId__index(anonUserId),
	index audit__createdAt__index(createdAt),

	constraint audit__userId__fk
		foreign key(userId)
		references user(id)
		on update cascade
		on delete set null
)
