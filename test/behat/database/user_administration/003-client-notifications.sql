create table ClientNotificationResponse(status integer not null);
insert into ClientNotificationResponse values (204);
create table ClientNotificationReceipt(userId text not null, email text not null, iv text not null, action text not null);

create table ClientUser(id text primary key, email text not null);
