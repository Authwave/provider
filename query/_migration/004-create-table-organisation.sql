create table organisation (
	id int auto_increment ,
	uuid varchar(32) unique not null ,
	name varchar(128) not null,

	primary key (id)
)