create table email (
	id int auto_increment ,
	userId int not null ,
	created datetime not null ,
	sent datetime ,
	toAddress varchar(256) not null ,
	fromAddress varchar(256) not null ,
	subject varchar(256) not null ,
	bodyText text ,
	bodyHtml text ,

	primary key (id) ,

	foreign key email__user (userId)
		references user (id)
		on update cascade
		on delete cascade
)