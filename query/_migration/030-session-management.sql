create table ApplicationSessionPolicy (
    applicationId char(64) not null primary key,
    maximumLifetimeMinutes int not null,
    idleTimeoutMinutes int not null,
    constraint session_policy_application_fk foreign key (applicationId)
        references Application(id) on delete cascade
);
alter table UserSession add column lastActivityAt datetime null;
alter table UserSession add column idleTimeoutMinutes int null;
alter table UserSession add column managed boolean not null default false;
