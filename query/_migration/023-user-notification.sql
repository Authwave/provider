alter table ApplicationDeployment add userNotificationUrl varchar(2048) null;

create table UserNotification (
    id char(64) not null primary key,
    userId char(64) not null,
    url varchar(2048) not null,
    createdAt datetime not null,
    attempts int not null default 0,
    nextAttemptAt datetime not null,
    deliveredAt datetime null,
    failedAt datetime null,
    statusCode smallint null,
    index user_notification_due_index(deliveredAt, failedAt, nextAttemptAt),
    foreign key (userId) references User(id) on update cascade on delete cascade
);
