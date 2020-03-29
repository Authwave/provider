create table user_identification (
	id int auto_increment ,
	userId int not null ,
	identificationType varchar (32) not null ,
	identificationDetail varchar (256) not null ,
	created datetime not null ,
	confirmed datetime ,
	confirmationCode varchar(32),

	primary key (id),

	foreign key user_identification__user (userId)
		references user (id)
		on update cascade
		on delete cascade
)