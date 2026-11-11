create table UserSession (
    id char(64) not null primary key,
    userId char(64) not null,
    createdAt datetime not null,
    expiresAt datetime not null,
    endedAt datetime null,
    index user_session_active_index(userId, endedAt, expiresAt),
    constraint user_session_user_fk foreign key (userId)
        references User(id) on update cascade on delete cascade
);
