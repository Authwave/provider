set foreign_key_checks = 0;

alter table Application
    drop foreign key application_production_application_deployment_id_fk;

alter table Email
    drop foreign key email_application_deployment_id_fk;

alter table UserAuthCode
    drop foreign key UserAuthCode_ibfk_1;

alter table User
    drop foreign key User_ibfk_1;

alter table ApplicationDeployment
    drop foreign key ApplicationDeployment_ibfk_1;

alter table Application
    modify id char(64) not null;

alter table ApplicationDeployment
    modify id char(64) not null,
    modify applicationId char(64) not null;

alter table User
    modify id char(64) not null,
    modify applicationDeploymentId char(64) not null;

alter table UserAuthCode
    modify id char(64) not null,
    modify userId char(64) not null;

alter table Email
    modify id char(64) not null,
    modify deploymentId char(64) not null;

alter table Application
    modify productionApplicationDeploymentId char(64) null;

alter table ApplicationDeployment
    add constraint application_deployment__applicationId__fk
        foreign key (applicationId)
        references Application(id)
        on update cascade
        on delete cascade;

alter table User
    add constraint user__applicationDeploymentId__fk
        foreign key (applicationDeploymentId)
        references ApplicationDeployment(id)
        on update cascade
        on delete cascade;

alter table UserAuthCode
    add constraint user_auth_code__userId__fk
        foreign key(userId)
        references User(id)
        on update cascade
        on delete cascade;

alter table Email
    add constraint email__deploymentId__fk
        foreign key (deploymentId) references ApplicationDeployment (id)
            on update cascade on delete cascade;

alter table Application
    add constraint application__productionApplicationDeploymentId__fk
        foreign key (productionApplicationDeploymentId) references ApplicationDeployment (id)
            on update cascade on delete set null;

set foreign_key_checks = 1;
