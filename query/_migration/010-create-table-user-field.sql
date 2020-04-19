create table user_field (
	id int auto_increment ,
	userId int not null ,
	fieldId int not null ,
	value varchar(1024) not null ,

	primary key (id) ,

	foreign key user_field__user (userId)
		references user(id)
		on update cascade
		on delete cascade ,

	foreign key user_field__application_field (fieldId)
		references application_field(id)
		on update cascade
		on delete cascade
)